<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [REFACTOR VAGUE 10] Implémentation concrète du fournisseur de releases.
 *
 * Extrait d'AppUpdateController (issue #45).
 */
final class GitHubReleaseProvider implements GitHubReleaseProviderInterface
{
    public function __construct(
        private readonly string $repo,
        private readonly string $cacheFile,
        private readonly int $cacheTtl = 3600,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getLatestRelease(): ?array
    {
        // 1. Cache
        if ($this->isCacheFresh()) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        // 2. Appel HTTP
        $url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: EPIClub-Update\r\n"
                          . "Accept: application/vnd.github.v3+json\r\n",
                'timeout' => 10,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            $err = error_get_last();
            error_log(sprintf(
                '[GitHubReleaseProvider] file_get_contents failed: %s (url=%s)',
                $err['message'] ?? 'unknown',
                $url
            ));
            return null;
        }

        // 3. Vérifier le code HTTP
        $httpCode = $this->extractHttpCode($http_response_header ?? []);
        if ($httpCode >= 400) {
            error_log(sprintf(
                '[GitHubReleaseProvider] GitHub API HTTP %d for %s',
                $httpCode,
                $url
            ));
            return null;
        }

        // 4. Décoder
        $release = json_decode($data, true);
        if (!is_array($release)
            || !isset($release['tag_name'], $release['zipball_url'])
        ) {
            error_log(sprintf(
                '[GitHubReleaseProvider] Unexpected GitHub API response: %s',
                substr($data, 0, 300)
            ));
            return null;
        }

        // 5. Construire le résultat
        //    ⚠️ FIX #45 : accepte body null (Release sans description)
        $result = [
            'tag'     => (string) $release['tag_name'],
            'zip_url' => (string) $release['zipball_url'],
            'body'    => (string) ($release['body'] ?? ''),
        ];

        $this->writeCache($result);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function downloadUrl(string $url): string
    {
        // 1. Tentative file_get_contents
        $content = @file_get_contents($url);
        if ($content !== false) {
            return $content;
        }

        // 2. Fallback cURL
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
                throw new \RuntimeException(
                    'cURL erreur : ' . $error . ' (HTTP ' . $httpCode . ')'
                );
            }
            if ($httpCode !== 200) {
                throw new \RuntimeException(
                    'HTTP ' . $httpCode . ' - ' . substr($content, 0, 200)
                );
            }
            return $content;
        }

        throw new \RuntimeException(
            'Impossible de télécharger (file_get_contents et cURL indisponibles).'
        );
    }

    // ==================================================================
    // Cache interne
    // ==================================================================

    private function isCacheFresh(): bool
    {
        return is_file($this->cacheFile)
            && (time() - filemtime($this->cacheFile) < $this->cacheTtl);
    }

    /**
     * @return array{tag:string,zip_url:string,body:string}|null
     */
    private function readCache(): ?array
    {
        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false) {
            return null;
        }

        $cached = json_decode($raw, true);
        if (!is_array($cached)
            || !isset($cached['tag'], $cached['zip_url'])
        ) {
            return null;
        }

        return [
            'tag'     => (string) $cached['tag'],
            'zip_url' => (string) $cached['zip_url'],
            'body'    => (string) ($cached['body'] ?? ''),
        ];
    }

    /**
     * @param array{tag:string,zip_url:string,body:string} $release
     */
    private function writeCache(array $release): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->cacheFile, json_encode($release));
    }

    /**
     * Extrait le code HTTP depuis l'en-tête de réponse.
     *
     * @param string[] $headers
     */
    private function extractHttpCode(array $headers): int
    {
        if (empty($headers[0])) {
            return 0;
        }
        if (preg_match('#HTTP/\S+\s+(\d+)#', $headers[0], $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}