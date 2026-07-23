<?php
// app/Controller/JournalController.php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Epiclub\Domain\JournalManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class JournalController extends AbstractController
{
    private JournalManager $journalManager;
    
    public function __construct(\Epiclub\Engine\Session $session)
    {
        parent::__construct($session);
        $this->journalManager = new JournalManager();
    }
    
    /**
     * Page de liste des journaux
     */
    public function index(): Response
    {
        if (!$this->isGranted('ROLE_USER')) {
            return $this->redirectTo('/se_connecter');
        }
        
        $filtres = [
            'controleur_id' => $_GET['controleur'] ?? null,
            'annee' => $_GET['annee'] ?? null,
            'order_by' => $_GET['order_by'] ?? 'date_debut',
            'order_dir' => $_GET['order_dir'] ?? 'desc',
            'limit' => (int)($_GET['limit'] ?? 10),
            'page' => (int)($_GET['page'] ?? 1)
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
        
        // Récupérer l'ID depuis les attributs de la requête
        $id = $request->attributes->get('id');
        
        if (!$id) {
            $this->session->getFlashBag()->add('danger', 'Identifiant du journal manquant');
            return $this->redirectTo('/journaux');
        }
        
        $controle = $this->journalManager->getControleCloture((int)$id);
        
        if (!$controle) {
            $this->session->getFlashBag()->add('danger', 'Journal de contrôle non trouvé');
            return $this->redirectTo('/journaux');
        }
        
        $lignes = $this->journalManager->getLignesControle((int)$id);
        
        return $this->render('journaux/voir.twig', [
            'controle' => $controle,
            'lignes' => $lignes
        ]);
    }
}