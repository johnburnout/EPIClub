<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use ZipArchive;

class UpdateController extends AbstractController
{
    private const GITHUB_REPO = 'johnburnout/EPIClub';
    private const VERSION_FILE = __DIR__ . '/../../version.txt';
    private const TEMP_DIR = __DIR__ . '/../../var/tmp/update';
    private const EXCLUDED_DIRS = ['var', 'vendor', '.git', '_storage', 'config', 'setup'];
    private const EXCLUDED_FILES = ['.env', '.env.local', '.env.local.php', 'version.txt'];

    // --------------------------------------------------------------
    // AFFICHAGE DE LA PAGE DE MISE À JOUR
    // --------------------------------------------------------------
    public function index(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->session->has('csrf_token')) {
            $this->session->set('csrf_token', bin2hex(random_bytes(32)));
        }
        $csrfToken = $this->session->get('csrf_token');

        $currentVersion = $this->getCurrentVersion();
        $latestRelease = $this->getLatestRelease();

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

        return $this->render('update.twig', [
            'current_version' => $currentVersion,
            'latest_version' => $latestRelease ? $latestRelease['tag'] : null,
            'update_available' => $updateAvailable,
            'can_update' => $canUpdate,
            'release_notes' => $latestRelease ? $latestRelease['body'] : null,
            'error' => $latestRelease === null ? 'Impossible de contacter GitHub.' : null,
            'csrf_token' => $csrfToken,
            'cleanup_needed' => $cleanupNeeded,
        ]);
    }

    // --------------------------------------------------------------
    // EXÉCUTION DE LA MISE À JOUR
    // --------------------------------------------------------------
    public function perform(Request $request): Response
    {
        try {
            $this->deniAccessUnlessGranted('ROLE_ADMIN');

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

            $latest = $this->getLatestRelease();
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
            $zipContent = $this->downloadUrl($zipUrl);
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
                $pdo = new \PDO(
                    'mysql:host=' . $_ENV['DB_HOST']
                    . ';dbname=' . $_ENV['DB_NAME']
                    . ';charset=utf8mb4',
                    $_ENV['DB_USER'],
                    $_ENV['DB_PASS'],
                    [
                        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    ]
                );

                $migrationManager = new \Epiclub\Engine\MigrationManager(
                    $pdo,
                    __DIR__ . '/../../migrations'
                );

                $applied = $migrationManager->migrate(function ($migrationVersion) {
                    error_log('[UpdateController] Migration appliquée : ' . $migrationVersion);
                });

                if (!empty($applied)) {
                    error_log(sprintf(
                        '[UpdateController] %d migration(s) appliquée(s).',
                        count($applied)
                    ));
                } else {
                    error_log('[UpdateController] Base déjà à jour.');
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[UpdateController] Migration failed: %s in %s:%d',
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
                '[UpdateController] Update failed: %s in %s:%d',
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
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

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

    private function getLatestRelease(): ?array
    {
        $cacheFile = self::TEMP_DIR . '/latest_release.json';
        $cacheTTL = 3600;

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached) {
                return $cached;
            }
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $options = [
            'http' => [
                'header' => "User-Agent: EPIClub-Update\r\nAccept: application/vnd.github.v3+json\r\n"
            ]
        ];
        $context = stream_context_create($options);
        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            return null;
        }

        $release = json_decode($data, true);
        if (!isset($release['tag_name'], $release['zipball_url'], $release['body'])) {
            return null;
        }

        $result = [
            'tag' => $release['tag_name'],
            'zip_url' => $release['zipball_url'],
            'body' => $release['body']
        ];

        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }
        file_put_contents($cacheFile, json_encode($result));

        return $result;
    }

    private function downloadUrl(string $url): string
    {
        $content = @file_get_contents($url);
        if ($content !== false) {
            return $content;
        }

        if (function_exists('curl_version')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'EPIClub-Update/1.0');
            $content = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($content === false) {
                throw new \Exception('cURL erreur : ' . $error . ' (HTTP ' . $httpCode . ')');
            }
            if ($httpCode !== 200) {
                throw new \Exception('HTTP ' . $httpCode . ' - ' . substr($content, 0, 200));
            }
            return $content;
        }

        throw new \Exception('Impossible de télécharger (file_get_contents et cURL indisponibles).');
    }

    private function copyFiles(string $source, string $target): void
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

    private function isExcluded(string $path): bool
    {
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
        $commands = [
            'composer install --no-dev --optimize-autoloader 2>&1',
            'php composer.phar install --no-dev --optimize-autoloader 2>&1'
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