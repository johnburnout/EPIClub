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
    private const EXCLUDED_DIRS = ['var', 'vendor', '.git', 'public/uploads', 'config', 'setup'];
    private const EXCLUDED_FILES = ['.env', '.env.local', '.env.local.php', 'version.txt'];

    // --------------------------------------------------------------
    // AFFICHAGE DE LA PAGE DE MISE À JOUR
    // --------------------------------------------------------------
    public function index(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        // Générer un token CSRF
        $csrfToken = bin2hex(random_bytes(32));
        $this->session->set('csrf_token', $csrfToken);

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

        // Vérifier si un nettoyage est nécessaire
        $cleanupNeeded = is_dir(self::TEMP_DIR . '/extracted');

        return $this->render('update.twig', [
            'current_version' => $currentVersion,
            'latest_version' => $latestRelease ? $latestRelease['tag'] : null,
            'update_available' => $updateAvailable,
            'can_update' => $canUpdate,
            'release_notes' => $latestRelease ? $latestRelease['body'] : null,
            'error' => $latestRelease === null ? 'Impossible de contacter GitHub.' : null,
            'csrf_token' => $csrfToken,
            'cleanup_needed' => $cleanupNeeded
        ]);
    }

    // --------------------------------------------------------------
    // EXÉCUTION DE LA MISE À JOUR
    // --------------------------------------------------------------
    public function perform(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        if ($request->getMethod() !== 'POST') {
            return new RedirectResponse('/admin/update');
        }

        // Vérifier CSRF
        $token = $request->request->get('csrf_token');
        if (!$token || $token !== $this->session->get('csrf_token')) {
            $this->addFlash('danger', 'Token invalide.');
            return new RedirectResponse('/admin/update');
        }

        $latest = $this->getLatestRelease();
        if (!$latest) {
            $this->addFlash('danger', 'Impossible de récupérer la version distante.');
            return new RedirectResponse('/admin/update');
        }

        $zipUrl = $latest['zip_url'];
        $version = $latest['tag'];

        $tempZip = self::TEMP_DIR . '/release.zip';
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }

        try {
            // 1. Télécharger le zip
            $zipContent = $this->downloadUrl($zipUrl);
            if (empty($zipContent)) {
                throw new \Exception('Le fichier téléchargé est vide.');
            }
            file_put_contents($tempZip, $zipContent);

            // 2. Décompresser
            $zip = new ZipArchive();
            if ($zip->open($tempZip) !== true) {
                throw new \Exception('Impossible d\'ouvrir le zip.');
            }
            $extractPath = self::TEMP_DIR . '/extracted';
            if (!is_dir($extractPath)) {
                mkdir($extractPath, 0755, true);
            }
            if (!$zip->extractTo($extractPath)) {
                throw new \Exception('Erreur lors de l\'extraction du zip.');
            }
            $zip->close();
            unlink($tempZip);

            // 3. Trouver le dossier source (nom dynamique)
            $extractedItems = scandir($extractPath);
            $sourceDir = null;
            foreach ($extractedItems as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($extractPath . '/' . $item)) {
                    $sourceDir = $extractPath . '/' . $item;
                    break;
                }
            }
            if (!$sourceDir) {
                throw new \Exception('Aucun dossier trouvé après extraction.');
            }

            // 4. Copier les fichiers (sauf exclus)
            $targetDir = __DIR__ . '/../..';
            $this->copyFiles($sourceDir, $targetDir);

            // 5. Nettoyer le dossier extrait
            $this->deleteDirectory($extractPath);

            // 6. Mettre à jour le numéro de version
            file_put_contents(self::VERSION_FILE, $version);

            // 7. Exécuter Composer (si possible)
            $this->runComposer();

            // 8. Nettoyage final : supprimer le dossier temporaire (si vide)
            $this->cleanupTempDir();

            $this->addFlash('success', 'Mise à jour terminée avec succès. Version : ' . $version);

        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur : ' . $e->getMessage());
            // Nettoyer en cas d'erreur
            if (isset($extractPath) && is_dir($extractPath)) {
                $this->deleteDirectory($extractPath);
            }
            if (file_exists($tempZip)) {
                unlink($tempZip);
            }
        }

        return new RedirectResponse('/admin/update');
    }

    // --------------------------------------------------------------
    // NETTOYAGE MANUEL DES FICHIERS TEMPORAIRES
    // --------------------------------------------------------------
    public function cleanup(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
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
        $cacheTTL = 3600; // 1 heure

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
        // Essayer file_get_contents
        $content = @file_get_contents($url);
        if ($content !== false) {
            return $content;
        }

        // Fallback cURL
        if (function_exists('curl_version')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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

        // Essayer la méthode PHP
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
            // Fallback via shell
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
        // Recréer le dossier temporaire vide
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
        if (method_exists($this->session, 'getFlashBag')) {
            $this->session->getFlashBag()->add($type, $message);
        } else {
            $flash = $this->session->get('flash_messages', []);
            $flash[$type][] = $message;
            $this->session->set('flash_messages', $flash);
        }
    }
}