<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Epiclub\Engine\DatabaseBackup;
use Epiclub\Engine\MySqlDatabaseDumper;
use Epiclub\Engine\GitHubReleaseProvider;
use Epiclub\Engine\SignatureMinisign;
use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Engine\VerrouInstance;
use Epiclub\Exception\MiseAJourException;
use Epiclub\Exception\VerrouOccupeException;
use Epiclub\Process\MiseAJourService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use ZipArchive;

class AppUpdateController extends AbstractController
{
    // NOTE : ce contrôleur ne délègue PAS à AbstractController::validateCsrf()
    // car les actions perform() et cleanup() rendent un message gracieux
    // (update_result.twig ou flash + redirect) plutôt que de propager
    // une AccessDeniedException. Comportement volontaire pour une opération
    // SUPER_ADMIN critique : on ne veut pas perdre l'utilisateur sur un 403 brut.
    // Le contrôle reste strictement équivalent à validateCsrf() (hash_equals).
    private const GITHUB_REPO = 'johnburnout/EPIClub';
    private const VERSION_FILE = __DIR__ . '/../../version.txt';
    private const TEMP_DIR = __DIR__ . '/../../var/tmp/update';
    // Listes d'exclusion de la copie : MiseAJourService.
    
    /**
    * [REFACTOR VAGUE 10] Factory du fournisseur de releases GitHub.
    *
    * Surchargée dans TestableAppUpdateController pour injecter un mock.
    * Voir GitHubReleaseProviderInterface (issue #45).
    */
    protected function githubReleaseProvider(): GitHubReleaseProviderInterface
    {
        return new GitHubReleaseProvider(
            self::GITHUB_REPO,
            self::TEMP_DIR . '/latest_release.json',
            3600,
            // Canal de mise à jour : « stable » (défaut) ou « beta »
            // (pré-releases incluses) — lu depuis .env.local.php.
            (string) $this->configProvider()->get('UPDATE_CHANNEL', 'stable'),
            // [v1.6.2] Jeton GitHub facultatif : limite de l'API portée de
            // 60 à 5 000 requêtes/heure (utile sur une IP partagée).
            is_string($t = $this->configProvider()->get('GITHUB_TOKEN')) ? $t : null,
        );
    }

    /**
     * [#101 - Dette 17] Factory PDO des migrations — mockable en test Unit.
     *
     * Remplace l'accès direct à $_ENV (dernier lecteur de $_ENV pour PDO).
     * Iso-comportement : bootstrap alimente $_ENV depuis .env.local.php,
     * donc $_ENV['DB_*'] === ConfigProvider->get('DB_*') au runtime web.
     * Clé absente → '' (avant : warning « undefined array key » + ''),
     * le tout restant dans le try/catch(\Throwable) de perform().
     */
    protected function databasePdo(): \PDO
    {
        $config = $this->configProvider();
        return new \PDO(
            $this->databaseDsn(),
            $config->get('DB_USER'),
            $config->get('DB_PASS'),
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * [#101] DSN PDO construit depuis ConfigProvider (source unique de
     * vérité, cf. Vague 12/#50).
     */
    protected function databaseDsn(): string
    {
        $config = $this->configProvider();
        return 'mysql:host=' . $config->get('DB_HOST')
            . ';dbname=' . $config->get('DB_NAME')
            . ';charset=utf8mb4';
    }

    /**
     * [v1.4.0] Sauvegarde SQL de la base si la nouvelle version apporte
     * des migrations non encore appliquées. Retention : BACKUP_RETENTION
     * (.env.local.php, défaut 5).
     *
     * @param string $migrationsLivrees Dossier migrations/ de l'archive.
     * @return string|null Fichier créé, ou null si aucune migration en attente.
     * @throws \Throwable si la sauvegarde échoue (la mise à jour doit s'arrêter).
     */
    protected function sauvegarderAvantMigrations(string $migrationsLivrees): ?string
    {
        return MiseAJourService::sauvegarderSiMigrationsEnAttente(
            $migrationsLivrees,
            fn (): array => $this->migrationsAppliquees(),
            $this->databaseBackup(),
            $this->configProvider()->get('BACKUP_RETENTION'),
        );
    }

    /** @return string[] */
    protected function migrationsAppliquees(): array
    {
        return (new \Epiclub\Engine\MigrationManager($this->databasePdo(), __DIR__ . '/../../migrations'))
            ->getAppliedVersions();
    }

    /**
     * Verrou commun de l'instance (var/epiclub.lock) : pas de mise à jour
     * pendant une sauvegarde planifiée, une restauration ou une
     * installation, ni l'inverse. Surchargé en test Unit.
     */
    protected function verrouInstance(): VerrouInstance
    {
        return VerrouInstance::pourRacine(__DIR__ . '/../..');
    }

    protected function databaseBackup(): DatabaseBackup
    {
        return new DatabaseBackup(
            new MySqlDatabaseDumper($this->databasePdo()),
            __DIR__ . '/../../var/backups'
        );
    }

    // --------------------------------------------------------------
    // AFFICHAGE DE LA PAGE DE MISE À JOUR
    // --------------------------------------------------------------
    public function index(Request $request): Response
    {
        // ⚠️ Mise à jour de l'application : réservé au SUPER_ADMIN
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->session->has('csrf_token')) {
            $this->session->set('csrf_token', bin2hex(random_bytes(32)));
        }
        $csrfToken = $this->session->get('csrf_token');

        $currentVersion = $this->getCurrentVersion();
        $latestRelease = $this->githubReleaseProvider()->getLatestRelease();

        $updateAvailable = false;
        if ($latestRelease) {
            $latestTag = ltrim($latestRelease['tag'], 'v');
            $current = ltrim($currentVersion, 'v');
            $updateAvailable = version_compare($latestTag, $current, '>');
        }

        // [v1.2.0-beta.2] Archive complète (vendor/ inclus) pas encore
        // attachée par la CI : on n'offre pas l'installation.
        $archiveEnPreparation = $updateAvailable && empty($latestRelease['bundle']);

        $canUpdate = $updateAvailable
            && !$archiveEnPreparation
            && is_writable(dirname(self::VERSION_FILE))
            && is_writable(__DIR__ . '/../..');

        $cleanupNeeded = is_dir(self::TEMP_DIR . '/extracted');

        $canal = (string) $this->configProvider()->get('UPDATE_CHANNEL', 'stable');
        if (!in_array($canal, GitHubReleaseProvider::CANAUX, true)) {
            $canal = 'stable';
        }

        return $this->render('update.twig', [
            'current_version' => $currentVersion,
            'latest_version' => $latestRelease ? $latestRelease['tag'] : null,
            'update_available' => $updateAvailable,
            'can_update' => $canUpdate,
            'release_notes' => $latestRelease ? $latestRelease['body'] : null,
            'error' => $latestRelease === null
                ? 'Impossible de contacter GitHub (limite de l\'API ou réseau). Nouvel essai automatique dans quelques minutes.'
                : null,
            'csrf_token' => $csrfToken,
            'cleanup_needed' => $cleanupNeeded,
            'update_channel' => $canal,
            'archive_en_preparation' => $archiveEnPreparation,
        ]);
    }

    // --------------------------------------------------------------
    // EXÉCUTION DE LA MISE À JOUR
    // --------------------------------------------------------------
    public function perform(Request $request): Response
    {
        // ⚠️ Mise à jour de l'application : réservé au SUPER_ADMIN
        // Vérification HORS du try pour que l'AccessDeniedException
        // remonte à la couche de gestion d'erreur (403 propre).
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $verrou = $this->verrouInstance();
        try {
            if ($request->getMethod() !== 'POST') {
                return new RedirectResponse('/admin/update');
            }

            $token = $request->request->get('csrf_token');
            $expected = $this->session->get('csrf_token');
            if (!$token || !$expected || !hash_equals((string) $expected, (string) $token)) {
                return $this->resultat([
                    'success' => false,
                    'message' => 'Token CSRF invalide. Merci de recharger la page.',
                ]);
            }

            // Verrou commun : refus immédiat si une sauvegarde, une
            // restauration ou une installation est en cours. Rien n'a
            // encore été téléchargé ni modifié.
            try {
                $verrou->acquerir('mise-a-jour');
            } catch (VerrouOccupeException $e) {
                return $this->resultat([
                    'success' => false,
                    'message' => $e->getMessage() . ' Rien n\'a été modifié.',
                ]);
            }

            // [v2.4.0] Déroulé commun avec bin/mettre-a-jour.php (console
            // d'hébergement) : Epiclub\Process\MiseAJourService.
            $reponse = null;
            try {
                $resultat = $this->miseAJourService()->mettreAJour(
                    static function (string $ligne): void {
                        error_log('[AppUpdateController] ' . $ligne);
                    },
                    true, // la page réinstalle la dernière version (comportement historique)
                    // [v1.5.1] La requête s'exécute avec l'ANCIEN code alors que
                    // la copie remplace les gabarits : page de résultat compilée
                    // avant la copie.
                    fn () => $this->prechargerGabaritsResultat(),
                    // [audit 2026-10-19 F1] Page de résultat rendue AVANT de
                    // toucher à vendor/ (une fois vendor/ remplacé, la requête
                    // ne doit plus charger de classe Twig).
                    function () use (&$reponse): void {
                        $reponse = $this->resultat([
                            'success' => true,
                            'message' => 'Mise à jour réussie. Version actuelle : ' . $this->versionLivree(),
                        ]);
                    },
                );
            } catch (MiseAJourException $e) {
                return $this->resultat(['success' => false, 'message' => $e->getMessage()]);
            }

            return $reponse ?? $this->resultat([
                'success' => true,
                'message' => 'Mise à jour réussie. Version actuelle : ' . $resultat['version'],
            ]);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AppUpdateController] Update failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            return $this->resultat([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour. Merci de réessayer ou de contacter un administrateur.',
            ]);
        } finally {
            $verrou->liberer();
        }
    }

    /** Version écrite dans version.txt par la mise à jour en cours. */
    private function versionLivree(): string
    {
        return $this->getCurrentVersion();
    }

    /** [v2.4.0] Service de mise à jour, branché sur les fabriques surchargeables en test. */
    protected function miseAJourService(): MiseAJourService
    {
        return new MiseAJourService(
            $this->githubReleaseProvider(),
            __DIR__ . '/../..',
            self::TEMP_DIR,
            fn (): \PDO => $this->databasePdo(),
            fn (string $migrationsLivrees): ?string => $this->sauvegarderAvantMigrations($migrationsLivrees),
            $this->signatureReleases(),
        );
    }

    /** [audit 2026-11-08 R1] Vérificateur des signatures de release (surchargé en test). */
    protected function signatureReleases(): SignatureMinisign
    {
        return new SignatureMinisign();
    }

    /**
     * [v1.5.1] Précharge la page de résultat et le gabarit parent avant
     * la copie des fichiers (cf. perform()).
     */
    protected function prechargerGabaritsResultat(): void
    {
        try {
            $this->prechargerGabarit('update_result.twig');
            $this->prechargerGabarit('_layout.twig');
        } catch (\Throwable $e) {
            error_log('[AppUpdateController] Préchargement des gabarits impossible : ' . $e->getMessage());
        }
    }

    /**
     * [v1.5.1] Page de résultat de la mise à jour. Si le rendu Twig
     * échoue (gabarits remplacés par la version suivante), repli sur une
     * page HTML minimale : le résultat réel de la mise à jour n'est
     * jamais masqué par une erreur d'affichage.
     *
     * @param array{success: bool, message: string, details?: string} $data
     */
    protected function resultat(array $data): Response
    {
        try {
            return $this->render('update_result.twig', $data);
        } catch (\Throwable $e) {
            error_log('[AppUpdateController] Page de résultat non affichable : ' . $e->getMessage());
            $titre = !empty($data['success']) ? '✅ Mise à jour réussie' : '❌ Erreur lors de la mise à jour';
            $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Mise à jour</title></head>'
                . '<body style="font-family: sans-serif; max-width: 40rem; margin: 3rem auto;">'
                . '<h1>' . $titre . '</h1>'
                . '<p>' . htmlspecialchars((string) ($data['message'] ?? ''), ENT_QUOTES) . '</p>'
                . '<p><a href="/admin/update">← Retour à la page de mise à jour</a> · <a href="/">Tableau de bord</a></p>'
                . '</body></html>';
            return new Response($html);
        }
    }

    // --------------------------------------------------------------
    // NETTOYAGE MANUEL DES FICHIERS TEMPORAIRES
    // --------------------------------------------------------------
    public function cleanup(Request $request): Response
    {
        // ⚠️ Nettoyage du dossier de mise à jour : réservé au SUPER_ADMIN
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($request->getMethod() !== 'POST') {
            return new RedirectResponse('/admin/update');
        }

        $token = $request->request->get('csrf_token');
        $expected = $this->session->get('csrf_token');
        if (!$token || !$expected || !hash_equals((string) $expected, (string) $token)) {
            $this->addFlash('error', 'Token CSRF invalide. Merci de réessayer.');
            return new RedirectResponse('/admin/update');
        }

        $this->cleanupTempDir();
        $this->addFlash('success', 'Dossier temporaire nettoyé.');
        return new RedirectResponse('/admin/update');
    }

    // --------------------------------------------------------------
    // MÉTHODES PRIVÉES
    // --------------------------------------------------------------

    private function getCurrentVersion(): string
    {
        if (file_exists(self::VERSION_FILE)) {
            return trim(file_get_contents(self::VERSION_FILE));
        }
        return 'v0.0.0';
    }

    // Méthodes conservées pour les tests Unit (TestableAppUpdateController) :
    // le code est dans MiseAJourService.

    protected function assertZipEntriesSafe(ZipArchive $zip): void
    {
        MiseAJourService::assertZipEntriesSafe($zip);
    }

    /** @param array<string, mixed> $release */
    protected function verifierSommeDeControle(array $release, string $zipContent): void
    {
        $this->miseAJourService()->verifierSommeDeControle($release, $zipContent);
    }

    protected function remplacerVendor(string $nouveau, string $cible): void
    {
        MiseAJourService::remplacerVendor($nouveau, $cible);
    }

    protected function copyFiles(string $source, string $target): void
    {
        MiseAJourService::copyFiles($source, $target);
    }

    protected function isExcluded(string $path): bool
    {
        return MiseAJourService::isExcluded($path);
    }

    private function cleanupTempDir(): void
    {
        if (is_dir(self::TEMP_DIR)) {
            MiseAJourService::deleteDirectory(self::TEMP_DIR);
        }
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }
    }

    private function addFlash(string $type, string $message): void
    {
        $this->session->getFlashBag()->add($type, $message);
    }
}