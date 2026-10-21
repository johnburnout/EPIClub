<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\ImageUploader;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tableau de bord (page d'accueil des utilisateurs connectés) et
 * réglages de sa personnalisation.
 *
 * Le bandeau gauche affiche :
 *   - le logo et le nom du club ;
 *   - une notification (fermable jusqu'à la prochaine connexion) quand
 *     des équipements arrivent en fin d'utilisation dans les N prochains
 *     mois, ou sont déjà échus (N = club.alerte_renouvellement_mois,
 *     réglable par les administrateurs) ;
 *   - un lien direct vers le scan de QR codes.
 *
 * Le logo et la photo de fond sont réglables par les administrateurs
 * (/admin/tableau-de-bord).
 */
class TableauDeBordController extends AbstractController
{
    /** Clé de session : notification fermée (effacée à la déconnexion). */
    public const SESSION_ALERTE_MASQUEE = 'tableau_de_bord.alerte_masquee';

    public const DUREE_ALERTE_DEFAUT = 3;
    public const DUREE_ALERTE_MIN = 1;
    public const DUREE_ALERTE_MAX = 36;

    public const LOGO_DEFAUT = '/assets/img/EPIClub.png';
    public const FOND_DEFAUT = '/assets/img/screen.jpg';

    /** URL publique du dossier des images du club. */
    private const IMAGES_URL = '/images/club/';

    /** Dimension maximale (px) après réencodage. */
    private const LOGO_MAX_DIMENSION = 400;
    private const FOND_MAX_DIMENSION = 1920;

    // ==================================================================
    // Factories — mockables en test Unit (pattern Vague 11)
    // ==================================================================

    protected function clubManager(): ClubManager
    {
        return new ClubManager();
    }

    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    /** [Prêt de matériel] Factory — surchargeable en test. */
    protected function pretManager(): \Epiclub\Domain\PretManager
    {
        return new \Epiclub\Domain\PretManager();
    }

    /** Dossier (servi par le serveur web) des images du club. */
    protected function imagesDir(): string
    {
        return __DIR__ . '/../../public/images/club/';
    }

    protected function imageUploader(int $maxDimension): ImageUploader
    {
        return new ImageUploader($this->imagesDir(), $maxDimension);
    }

    /** Date du jour — surchargeable en test. */
    protected function aujourdhui(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    // ==================================================================
    // Tableau de bord
    // ==================================================================

    public function index(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $club = $this->clubManager()->findParameters() ?: [];
        $mois = self::normaliserDuree($club['alerte_renouvellement_mois'] ?? null);

        $aujourdhui = $this->aujourdhui();
        $dateLimite = $aujourdhui->modify('+' . $mois . ' months');

        $alerte = null;
        if (!$this->session->get(self::SESSION_ALERTE_MASQUEE)) {
            try {
                $stats = $this->equipementManager()->statsARenouveler(
                    $aujourdhui->format('Y-m-d'),
                    $dateLimite->format('Y-m-d')
                );
            } catch (\Throwable $e) {
                // Le tableau de bord doit rester affichable même si le
                // calcul échoue (ex. migration 012 pas encore jouée).
                error_log('[TableauDeBordController] statsARenouveler: ' . $e->getMessage());
                $stats = ['total' => 0, 'echus' => 0];
            }

            if ($stats['total'] > 0) {
                $alerte = [
                    'total' => $stats['total'],
                    'echus' => $stats['echus'],
                    'mois' => $mois,
                    'date_limite' => $dateLimite->format('Y-m-d'),
                    'url' => '/equipements?' . http_build_query([
                        'fin_avant' => $dateLimite->format('Y-m-d'),
                        'order_by' => 'date_fin',
                        'order_dir' => 'asc',
                        'limit' => 100,
                    ]),
                ];
            }
        }

        // [Prêt de matériel] Prêts en cours dont la date de retour prévue
        // est dépassée (contrôleurs, module activé). Non fermable : la
        // notification disparaît quand les prêts sont terminés.
        $pretsEnRetard = 0;
        if ($this->isGranted('ROLE_CONTROLLEUR') && $this->pretActif()) {
            try {
                $pretsEnRetard = $this->pretManager()->countEnRetard($aujourdhui->format('Y-m-d'));
            } catch (\Throwable $e) {
                error_log('[TableauDeBordController] countEnRetard: ' . $e->getMessage());
            }
        }

        return $this->render('tableau_de_bord.twig', [
            'prets_en_retard' => $pretsEnRetard,
            'club' => $club,
            'logo_url' => self::imageUrl($club['logo'] ?? null, self::LOGO_DEFAUT),
            'fond_url' => self::imageUrl($club['image_fond'] ?? null, self::FOND_DEFAUT),
            'alerte' => $alerte,
        ]);
    }

    /**
     * Ferme la notification jusqu'à la prochaine connexion (la session
     * est vidée à la déconnexion). Appel AJAX (JSON) ou formulaire
     * classique (redirection) si JavaScript est indisponible.
     */
    public function masquerAlerte(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        $this->validateCsrf($request);

        $this->session->set(self::SESSION_ALERTE_MASQUEE, true);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }
        return $this->redirectTo('/tableau_de_bord');
    }

    // ==================================================================
    // Réglages (ROLE_ADMIN)
    // ==================================================================

    public function reglages(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $club = $this->clubManager()->findParameters() ?: [];
        $duree = self::normaliserDuree($club['alerte_renouvellement_mois'] ?? null);
        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            $this->validateCsrf($request);

            if ($this->isPostTruncated($request)) {
                $form_errors['images'] = sprintf(
                    'Le fichier est trop volumineux pour être traité par le serveur (limite PHP : %s).',
                    ini_get('post_max_size')
                );
            } else {
                $dureeRaw = trim((string) $request->request->get('alerte_renouvellement_mois', ''));
                if (!ctype_digit($dureeRaw)
                    || (int) $dureeRaw < self::DUREE_ALERTE_MIN
                    || (int) $dureeRaw > self::DUREE_ALERTE_MAX
                ) {
                    $form_errors['alerte_renouvellement_mois'] = sprintf(
                        'La durée doit être un nombre entier de mois entre %d et %d.',
                        self::DUREE_ALERTE_MIN,
                        self::DUREE_ALERTE_MAX
                    );
                } else {
                    $duree = (int) $dureeRaw;
                }

                $maj = ['id' => 1, 'alerte_renouvellement_mois' => $duree];
                $aSupprimer = [];

                foreach ([
                    'logo' => self::LOGO_MAX_DIMENSION,
                    'image_fond' => self::FOND_MAX_DIMENSION,
                ] as $champ => $maxDimension) {
                    $fichier = $request->files->get($champ);
                    if ($fichier instanceof UploadedFile) {
                        try {
                            $maj[$champ] = $this->imageUploader($maxDimension)
                                ->save($fichier, $champ === 'logo' ? 'logo' : 'fond');
                            if (!empty($club[$champ])) {
                                $aSupprimer[] = $club[$champ];
                            }
                        } catch (\InvalidArgumentException $e) {
                            $form_errors[$champ] = $e->getMessage();
                        } catch (\Throwable $e) {
                            error_log('[TableauDeBordController] Upload ' . $champ . ': ' . $e->getMessage());
                            $form_errors[$champ] = "Erreur lors du téléversement de l'image.";
                        }
                    } elseif ($request->request->has('supprimer_' . $champ) && !empty($club[$champ])) {
                        // Retour à l'image par défaut
                        $maj[$champ] = null;
                        $aSupprimer[] = $club[$champ];
                    }
                }

                if (empty($form_errors)) {
                    $this->clubManager()->save($maj);
                    foreach ($aSupprimer as $ancien) {
                        $this->supprimerImage($ancien);
                    }
                    $this->session->getFlashBag()->add('success', 'Les réglages du tableau de bord ont été enregistrés.');
                    return $this->redirectTo('/admin/tableau-de-bord');
                }

                // Échec : on ne garde pas les nouveaux fichiers orphelins.
                foreach (['logo', 'image_fond'] as $champ) {
                    if (!empty($maj[$champ]) && ($maj[$champ] !== ($club[$champ] ?? null))) {
                        $this->supprimerImage($maj[$champ]);
                    }
                }
            }
        }

        return $this->render('tableau_de_bord_reglages.twig', [
            'club' => $club,
            'duree' => $duree,
            'duree_min' => self::DUREE_ALERTE_MIN,
            'duree_max' => self::DUREE_ALERTE_MAX,
            'logo_url' => self::imageUrl($club['logo'] ?? null, self::LOGO_DEFAUT),
            'fond_url' => self::imageUrl($club['image_fond'] ?? null, self::FOND_DEFAUT),
            'logo_personnalise' => !empty($club['logo']),
            'fond_personnalise' => !empty($club['image_fond']),
            'form_errors' => $form_errors,
        ]);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /** Durée valide (bornée) ; valeur absente ou invalide → défaut. */
    public static function normaliserDuree(mixed $valeur): int
    {
        if ($valeur === null || $valeur === '' || !is_numeric($valeur)) {
            return self::DUREE_ALERTE_DEFAUT;
        }
        return max(self::DUREE_ALERTE_MIN, min(self::DUREE_ALERTE_MAX, (int) $valeur));
    }

    private static function imageUrl(?string $fichier, string $defaut): string
    {
        if ($fichier === null || $fichier === '') {
            return $defaut;
        }
        return self::IMAGES_URL . rawurlencode(basename($fichier));
    }

    private function supprimerImage(string $fichier): void
    {
        $chemin = $this->imagesDir() . basename($fichier);
        if (is_file($chemin)) {
            @unlink($chemin);
        }
    }
}
