<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Epiclub\Engine\GitHubReleaseProvider;
use Epiclub\Engine\GitHubReleaseProviderInterface;
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
    // [audit 2026-10-19 F2] 'public' n'est PLUS exclu : l'exclure
    // empêchait toute mise à jour de index.php, des .htaccess et des
    // assets (le durcissement public/.htaccess de la beta.4 n'atteignait
    // jamais les instances mises à jour). L'exclusion n'apportait pas de
    // vraie protection : app/ est copié et exécuté de toute façon. La
    // protection contre un zip compromis relève de la vérification
    // d'intégrité de l'archive (audit S3), pas de cette liste.
    private const EXCLUDED_DIRS = ['var', 'vendor', '.git', '_storage', 'config', 'setup'];

    // [audit 2026-10-19 F2] Données des utilisateurs dans le docroot
    // (photos d'équipement, QR codes, images de catégorie) : jamais
    // écrasées ni complétées par une release. Seuls les .htaccess de
    // protection de ce dossier sont mis à jour.
    private const USER_DATA_DIR = 'public/images';
    private const USER_DATA_ALLOWED_FILES = ['.htaccess'];
    private const EXCLUDED_FILES = ['.env', '.env.local', '.env.local.php', 'version.txt'];
    
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

        $canUpdate = $updateAvailable
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
            'error' => $latestRelease === null ? 'Impossible de contacter GitHub.' : null,
            'csrf_token' => $csrfToken,
            'cleanup_needed' => $cleanupNeeded,
            'update_channel' => $canal,
        ]);
    }

    // --------------------------------------------------------------
    // EXÉCUTION DE LA MISE À JOUR
    // --------------------------------------------------------------
    public function perform(Request $request): Response
    {
        // ⚠️ Mise à jour de l'application : réservé au SUPER_ADMIN
        // Vérification HORS du try pour que l'AccessDeniedException
        // remonte à la couche de gestion d'erreur (403 propre) au lieu
        // d'être rattrapée par le catch(\Throwable) du bas et transformée
        // en message d'erreur générique.
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        
        try {
            if ($request->getMethod() !== 'POST') {
                return new RedirectResponse('/admin/update');
            }
            
            $token = $request->request->get('csrf_token');
            $expected = $this->session->get('csrf_token');
            if (!$token || !$expected || !hash_equals((string) $expected, (string) $token)) {
                return $this->render('update_result.twig', [
                    'success' => false,
                    'message' => 'Token CSRF invalide. Merci de recharger la page.',
                ]);
            }
            
            $latest = $this->githubReleaseProvider()->getLatestRelease();
            if (!$latest) {
                return $this->render('update_result.twig', [
                    'success' => false,
                    'message' => 'Impossible de récupérer la version distante.',
                ]);
            }
            
            $zipUrl = $latest['zip_url'];
            $version = $latest['tag'];
            
            $tempZip = self::TEMP_DIR . '/release.zip';
            if (!is_dir(self::TEMP_DIR)) {
                mkdir(self::TEMP_DIR, 0755, true);
            }
            
            // Télécharger le zip
            $zipContent = $this->githubReleaseProvider()->downloadUrl($zipUrl);
            if (empty($zipContent)) {
                return $this->render('update_result.twig', [
                    'success' => false,
                    'message' => 'Le fichier téléchargé est vide.',
                ]);
            }
            file_put_contents($tempZip, $zipContent);
            
            // Décompresser
            $zip = new ZipArchive();
            if ($zip->open($tempZip) !== true) {
                return $this->render('update_result.twig', [
                    'success' => false,
                    'message' => 'Impossible d\'ouvrir l\'archive téléchargée.',
                ]);
            }

            // [SÉCURITÉ — audit C1] Validation des entrées AVANT extraction :
            // rejet des chemins « .. », absolus ou Windows (zip slip). Un zip
            // GitHub compromis ne doit pas pouvoir écrire hors du dossier
            // d'extraction.
            try {
                $this->assertZipEntriesSafe($zip);
            } catch (\RuntimeException $e) {
                $zip->close();
                @unlink($tempZip);
                error_log('[AppUpdateController] Archive rejetée : ' . $e->getMessage());
                return $this->render('update_result.twig', [
                    'success' => false,
                    'message' => 'Archive invalide : le fichier téléchargé a été rejeté pour des raisons de sécurité.',
                ]);
            }

            $extractPath = self::TEMP_DIR . '/extracted';
            if (!is_dir($extractPath)) {
                mkdir($extractPath, 0755, true);
            }
            if (!$zip->extractTo($extractPath)) {
                return $this->render('update_result.twig', [
                    'success' => false,
                    'message' => 'Erreur lors de l\'extraction de l\'archive.',
                ]);
            }
            $zip->close();
            unlink($tempZip);
            
            // Trouver le dossier source
            $extractedItems = scandir($extractPath);
            $sourceDir = null;
            foreach ($extractedItems as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($extractPath . '/' . $item)) {
                    $sourceDir = $extractPath . '/' . $item;
                    break;
                }
            }
            if (!$sourceDir) {
                return $this->render('update_result.twig', [
                    'success' => false,
                    'message' => 'Aucun dossier trouvé après extraction.',
                ]);
            }
            
            // Copier les fichiers (sauf exclus)
            $targetDir = __DIR__ . '/../..';
            $this->copyFiles($sourceDir, $targetDir);
            
            // ------------------------------------------------------------
            // MIGRATIONS DE BASE DE DONNÉES
            // ------------------------------------------------------------
            try {
                $pdo = $this->databasePdo();
                
                $migrationManager = new \Epiclub\Engine\MigrationManager(
                    $pdo,
                    __DIR__ . '/../../migrations'
                );
                
                $applied = $migrationManager->migrate(function ($migrationVersion) {
                    error_log('[AppUpdateController] Migration appliquée : ' . $migrationVersion);
                });
                
                if (!empty($applied)) {
                    error_log(sprintf(
                        '[AppUpdateController] %d migration(s) appliquée(s).',
                        count($applied)
                    ));
                } else {
                    error_log('[AppUpdateController] Base déjà à jour.');
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[AppUpdateController] Migration failed: %s in %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
                
                return $this->render('update_result.twig', [
                    'success' => false,
                    'message' => 'Les fichiers ont été mis à jour mais les migrations '
                    . 'de base de données ont échoué. Consultez les logs '
                    . 'serveur avant de relancer l\'application.',
                ]);
            }
            
            // Nettoyer le dossier extrait
            $this->deleteDirectory($extractPath);
            
            // Mettre à jour le numéro de version
            file_put_contents(self::VERSION_FILE, $version);
            
            // Exécuter Composer (si possible)
            $this->runComposer();
            
            // Nettoyage final
            $this->cleanupTempDir();
            
            return $this->render('update_result.twig', [
                'success' => true,
                'message' => 'Mise à jour réussie. Version actuelle : ' . $version,
            ]);
            
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AppUpdateController] Update failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            
            return $this->render('update_result.twig', [
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour. Merci de réessayer ou de contacter un administrateur.',
            ]);
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

    /**
     * [SÉCURITÉ — audit C1] Rejette les archives contenant des entrées
     * dangereuses : « .. » (zip slip), chemins absolus Unix (« / ») ou
     * Windows (« C:\ », « \ »), backslashes.
     *
     * Méthode protected pour être testable en Unit (sous-classe de test).
     *
     * @throws \RuntimeException si une entrée est malveillante
     */
    protected function assertZipEntriesSafe(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                throw new \RuntimeException("Entrée zip #$i illisible");
            }
            if (str_contains($name, '..') || preg_match('#^[/\\\\]#', $name) || str_contains($name, '\\')) {
                throw new \RuntimeException(sprintf('Entrée zip malveillante : %s', $name));
            }
        }
    }

    /**
     * Copie récursive de la release extraite vers l'installation, en
     * respectant isExcluded(). Protected : testable en Unit.
     */
    protected function copyFiles(string $source, string $target): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath = $target . '/' . $relativePath;

            if ($this->isExcluded($relativePath)) {
                continue;
            }

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $parent = dirname($targetPath);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    protected function isExcluded(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        // [audit 2026-10-19 F2] Dossier de données utilisateur du docroot :
        // le dossier lui-même et ses sous-dossiers sont ignorés (copyFiles
        // crée les parents nécessaires), seuls les .htaccess passent.
        if ($path === self::USER_DATA_DIR || str_starts_with($path, self::USER_DATA_DIR . '/')) {
            return !in_array(basename($path), self::USER_DATA_ALLOWED_FILES, true);
        }

        foreach (self::EXCLUDED_DIRS as $dir) {
            if (strpos($path, $dir . '/') === 0 || $path === $dir) {
                return true;
            }
        }
        foreach (self::EXCLUDED_FILES as $file) {
            if (basename($path) === $file) {
                return true;
            }
        }
        return false;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        try {
            $iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($dir);
        } catch (\Exception $e) {
            if (function_exists('system')) {
                system('rm -rf ' . escapeshellarg($dir));
            }
        }
    }

    private function cleanupTempDir(): void
    {
        if (is_dir(self::TEMP_DIR)) {
            $this->deleteDirectory(self::TEMP_DIR);
        }
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }
    }

    private function runComposer(): void
    {
        // [SÉCURITÉ — audit C1] --no-scripts : les handlers Composer
        // (post-install-cmd, etc.) du composer.json potentiellement
        // compromis ne doivent JAMAIS être exécutés par le processus web.
        $commands = [
            'composer install --no-dev --no-scripts --optimize-autoloader 2>&1',
            'php composer.phar install --no-dev --no-scripts --optimize-autoloader 2>&1'
        ];

        foreach ($commands as $cmd) {
            $output = shell_exec($cmd);
            error_log('Composer output: ' . ($output ?: 'aucune sortie'));
        }
    }

    private function addFlash(string $type, string $message): void
    {
        $this->session->getFlashBag()->add($type, $message);
    }
}