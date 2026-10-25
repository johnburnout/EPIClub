<?php
// app/Controller/JournalController.php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Epiclub\Engine\Pagination;
use Epiclub\Engine\EncryptionService;
use Epiclub\Domain\JournalManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Dompdf\Dompdf;
use Dompdf\Options;

class JournalController extends AbstractController
{
    protected JournalManager $journalManager;
    protected EncryptionService $encryptionService;

    public function __construct(\Epiclub\Engine\Session $session)
    {
        parent::__construct($session);
        $this->journalManager = $this->journalManager();
        $this->encryptionService = $this->encryptionService();
    }

    /**
     * Page de liste des journaux
     *
     * [#100 - Dette 15] Accepte désormais le Request du dispatcher et lit
     * les filtres via $request->query au lieu de $_GET (testable en
     * Unit). Garde « redirect vers /se_connecter » conservée
     * volontairement (iso-comportement, cf. docblock du test).
     */
    public function index(Request $request): Response
    {
        if (!$this->isGranted('ROLE_USER')) {
            return $this->redirectTo('/se_connecter');
        }

        $filtres = [
            'controleur_id' => $request->query->get('controleur'),
            'annee' => $request->query->get('annee'),
            'order_by' => $request->query->get('order_by', 'date_debut'),
            'order_dir' => $request->query->get('order_dir', 'desc'),
            'limit' => Pagination::limite($request->query->get('limit')),
            'page' => (int) $request->query->get('page', 1)
        ];

        $resultat = $this->journalManager->getControlesClotures($filtres);
        $controleurs = $this->journalManager->getControleurs();
        $annees = $this->journalManager->getAnneesDisponibles();

        $baseParams = array_filter([
            'controleur' => $filtres['controleur_id'],
            'annee' => $filtres['annee'],
            'order_by' => $filtres['order_by'],
            'order_dir' => $filtres['order_dir'],
            'limit' => $filtres['limit']
        ]);

        $queryString = !empty($baseParams) ? '?' . http_build_query($baseParams) : '';
        $paginationUrls = [
            'first' => "/journaux{$queryString}&page=1",
            'previous' => "/journaux{$queryString}&page=" . ($filtres['page'] - 1),
            'next' => "/journaux{$queryString}&page=" . ($filtres['page'] + 1),
            'last' => "/journaux{$queryString}&page=" . $resultat['totalPages']
        ];

        return $this->render('journaux/index.twig', [
            'journaux' => $resultat['data'],
            'controleurs' => $controleurs,
            'annees' => $annees,
            'filtres' => $filtres,
            'pagination' => [
                'page' => $resultat['page'],
                'totalPages' => $resultat['totalPages'],
                'total' => $resultat['total'],
                'hasPrevious' => $resultat['page'] > 1,
                'hasNext' => $resultat['page'] < $resultat['totalPages']
            ],
            'paginationUrls' => $paginationUrls
        ]);
    }

    /**
     * Visualisation d'un journal
     */
    public function voir(Request $request): Response
    {
        if (!$this->isGranted('ROLE_USER')) {
            return $this->redirectTo('/se_connecter');
        }

        // getValidId() : id de route strictement numérique, sinon null.
        $id = $this->getValidId($request);
        if ($id === null) {
            $this->session->getFlashBag()->add('danger', 'Identifiant du journal manquant');
            return $this->redirectTo('/journaux');
        }

        $controle = $this->journalManager->getControleCloture($id);
        if (!$controle) {
            $this->session->getFlashBag()->add('danger', 'Journal de contrôle non trouvé');
            return $this->redirectTo('/journaux');
        }

        // Déchiffrer les remarques générales du contrôle
        if (!empty($controle['hash_remarques'])) {
            $controle['hash_remarques'] = $this->encryptionService->decrypt($controle['hash_remarques']);
        }

        $lignes = $this->journalManager->getLignesControle((int)$id);
        // Déchiffrer les remarques de chaque ligne
        foreach ($lignes as &$ligne) {
            if (!empty($ligne['remarque'])) {
                $ligne['remarque'] = $this->encryptionService->decrypt($ligne['remarque']);
            }
        }

        return $this->render('journaux/voir.twig', [
            'controle' => $controle,
            'lignes' => $lignes
        ]);
    }

    /**
     * Génération PDF d'un journal
     */
    public function pdf(Request $request): Response
    {
        if (!$this->isGranted('ROLE_USER')) {
            return $this->redirectTo('/se_connecter');
        }

        // getValidId() : id de route strictement numérique, sinon null.
        $id = $this->getValidId($request);
        if ($id === null) {
            $this->session->getFlashBag()->add('danger', 'Identifiant du journal manquant');
            return $this->redirectTo('/journaux');
        }

        $controle = $this->journalManager->getControleCloture($id);
        if (!$controle) {
            $this->session->getFlashBag()->add('danger', 'Journal de contrôle non trouvé');
            return $this->redirectTo('/journaux');
        }

        // Déchiffrer les remarques générales
        if (!empty($controle['hash_remarques'])) {
            $controle['hash_remarques'] = $this->encryptionService->decrypt($controle['hash_remarques']);
        }

        $lignes = $this->journalManager->getLignesControle((int)$id);
        // Déchiffrer les remarques de chaque ligne
        foreach ($lignes as &$ligne) {
            if (!empty($ligne['remarque'])) {
                $ligne['remarque'] = $this->encryptionService->decrypt($ligne['remarque']);
            }
        }

        // Génération du HTML pour le PDF
        $html = $this->generatePdfHtml($controle, $lignes);

        // Configuration de Dompdf
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'journal_controle_' . $controle['id'] . '_' . date('Y-m-d') . '.pdf';
        $output = $dompdf->output();

        return new Response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Content-Length' => strlen($output)
        ]);
    }

    /**
     * Génère le HTML pour le PDF en utilisant la méthode render() du parent
     */
    private function generatePdfHtml(array $controle, array $lignes): string
    {
        $response = $this->render('journaux/pdf.twig', [
            'controle' => $controle,
            'lignes' => $lignes,
            'generated_at' => date('d/m/Y H:i:s')
        ]);

        return $response->getContent();
    }

    protected function journalManager(): JournalManager
    {
        return new JournalManager();
    }
}