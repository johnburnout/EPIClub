<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\JournalActiviteManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\Pagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [migration 019] Journal d'activité (connexions, contrôles, prêts).
 *
 *   - consultation filtrée et export texte : ROLE_ADMIN ;
 *   - réinitialisation : ROLE_SUPER_ADMIN, avec saisie du mot
 *     « REINITIALISER » comme garde-fou. La réinitialisation est
 *     elle-même inscrite dans le journal vidé.
 */
class JournalActiviteController extends AbstractController
{
    /** Mot à saisir pour confirmer la réinitialisation. */
    public const CONFIRMATION = 'REINITIALISER';

    private const URL = '/admin/journal-activite';

    /** Factory du manager consulté — surchargeable en test. */
    protected function lectureManager(): JournalActiviteManager
    {
        return new JournalActiviteManager();
    }

    /** Date et heure courantes — surchargeables en test. */
    protected function maintenant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    // ==================================================================
    // Consultation
    // ==================================================================

    public function index(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $filtres = self::filtres($request);
        $manager = $this->lectureManager();

        $pagination = Pagination::pourTotal($manager->compter($filtres), $request);
        $entrees = $manager->rechercher($filtres, $pagination['limit'], $pagination['offset']);

        return $this->render('journal_activite.twig', [
            'entrees' => $entrees,
            'filtres' => $filtres,
            'types' => JournalActiviteManager::TYPES,
            'pagination' => $pagination,
            'export_url' => self::URL . '/export' . self::queryFiltres($filtres),
            'confirmation' => self::CONFIRMATION,
        ]);
    }

    // ==================================================================
    // Export texte
    // ==================================================================

    public function export(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $filtres = self::filtres($request);
        $entrees = $this->lectureManager()->rechercher($filtres);
        $contenu = self::texte($entrees, $filtres, $this->maintenant());

        $nom = 'journal_activite_' . $this->maintenant()->format('Y-m-d_His') . '.txt';

        return new Response($contenu, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nom . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Contenu du fichier texte (pur, testable) : en-tête puis une ligne
     * par entrée, de la plus récente à la plus ancienne.
     *
     * @param list<array<string, mixed>> $entrees
     */
    public static function texte(array $entrees, array $filtres, \DateTimeImmutable $genereLe): string
    {
        $lignes = [
            "Journal d'activité EPIClub",
            'Exporté le ' . $genereLe->format('d/m/Y à H:i:s'),
        ];

        $criteres = [];
        if (!empty($filtres['type'])) {
            $criteres[] = 'type : ' . (JournalActiviteManager::TYPES[$filtres['type']] ?? $filtres['type']);
        }
        if (!empty($filtres['du'])) {
            $criteres[] = 'du ' . date('d/m/Y', (int) strtotime($filtres['du']));
        }
        if (!empty($filtres['au'])) {
            $criteres[] = 'au ' . date('d/m/Y', (int) strtotime($filtres['au']));
        }
        if (!empty($filtres['q'])) {
            $criteres[] = 'recherche : « ' . $filtres['q'] . ' »';
        }
        if ($criteres !== []) {
            $lignes[] = 'Filtres : ' . implode(', ', $criteres);
        }
        $lignes[] = sprintf('%d entrée%s', count($entrees), count($entrees) > 1 ? 's' : '');
        $lignes[] = str_repeat('=', 72);

        foreach ($entrees as $e) {
            $lignes[] = implode(' | ', [
                date('d/m/Y H:i:s', (int) strtotime((string) $e['date_action'])),
                JournalActiviteManager::TYPES[$e['type']] ?? (string) $e['type'],
                ($e['utilisateur'] ?? '') !== '' ? self::uneLigne((string) $e['utilisateur']) : '—',
                self::uneLigne((string) $e['action']),
                'IP ' . (($e['ip'] ?? '') !== '' ? $e['ip'] : '—'),
            ]);
        }

        // Fins de ligne Windows : lisible dans le Bloc-notes comme ailleurs.
        return implode("\r\n", $lignes) . "\r\n";
    }

    // ==================================================================
    // Réinitialisation (ROLE_SUPER_ADMIN)
    // ==================================================================

    public function reinitialiser(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $this->validateCsrf($request);

        $saisie = trim((string) $request->request->get('confirmation', ''));
        if ($saisie !== self::CONFIRMATION) {
            $this->session->getFlashBag()->add(
                'error',
                sprintf('Journal non réinitialisé : saisissez exactement « %s » pour confirmer.', self::CONFIRMATION)
            );
            return $this->redirectTo(self::URL);
        }

        $supprimees = $this->lectureManager()->reinitialiser();
        $this->journaliser(
            JournalActiviteManager::TYPE_JOURNAL,
            sprintf('Réinitialisation du journal (%d entrée%s supprimée%s)', $supprimees, $supprimees > 1 ? 's' : '', $supprimees > 1 ? 's' : ''),
            $request
        );

        $this->session->getFlashBag()->add('success', "Le journal d'activité a été réinitialisé.");
        return $this->redirectTo(self::URL);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Filtres validés : type connu, dates Y-m-d valides, recherche
     * nettoyée (100 caractères) ; les autres valeurs sont ignorées.
     *
     * @return array{type: ?string, du: ?string, au: ?string, q: ?string}
     */
    public static function filtres(Request $request): array
    {
        $type = $request->query->get('type');
        $q = $request->query->get('q');
        $q = is_string($q) ? trim(mb_substr($q, 0, 100)) : '';

        return [
            'type' => is_string($type) && isset(JournalActiviteManager::TYPES[$type]) ? $type : null,
            'du' => self::date($request->query->get('du')),
            'au' => self::date($request->query->get('au')),
            'q' => $q === '' ? null : $q,
        ];
    }

    private static function date(mixed $valeur): ?string
    {
        if (!is_string($valeur) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        return ($d !== false && $d->format('Y-m-d') === $valeur) ? $valeur : null;
    }

    private static function queryFiltres(array $filtres): string
    {
        $params = array_filter($filtres, static fn ($v) => $v !== null && $v !== '');
        return $params === [] ? '' : '?' . http_build_query($params);
    }

    /** Une entrée = une ligne du fichier (pas de saut de ligne injecté). */
    private static function uneLigne(string $texte): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $texte));
    }
}
