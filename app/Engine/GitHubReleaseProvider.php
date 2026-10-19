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
    /** Canaux de mise à jour : stable = /releases/latest ; beta = pré-releases incluses. */
    public const CANAUX = ['stable', 'beta'];

    private readonly string $channel;

    public function __construct(
        private readonly string $repo,
        private readonly string $cacheFile,
        private readonly int $cacheTtl = 3600,
        string $channel = 'stable',
    ) {
        $this->channel = in_array($channel, self::CANAUX, true) ? $channel : 'stable';
    }

    /**
     * {@inheritdoc}
     *
     * Canal « stable » : endpoint /releases/latest (qui ne retourne
     * JAMAIS les pré-releases). Canal « beta » : liste des releases et
     * sélection de la plus récente, pré-releases comprises — permet à
     * une instance de test de suivre les versions bêta.
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
        if ($this->channel === 'beta') {
            $releases = $this->fetchJson(
                'https://api.github.com/repos/' . $this->repo . '/releases?per_page=10'
            );
            $release = is_array($releases) ? self::choisirPlusRecente($releases) : null;
        } else {
            $release = $this->fetchJson(
                'https://api.github.com/repos/' . $this->repo . '/releases/latest'
            );
        }

        if (!is_array($release)
            || !isset($release['tag_name'], $release['zipball_url'])
        ) {
            return null;
        }

        // 3. Construire le résultat
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
     * Sélectionne la release la plus récente dans une liste de l'API
     * /releases (pré-releases incluses, drafts exclus). Comparaison
     * semver via version_compare (1.0.0-beta.2 > 1.0.0-beta.1, mais
     * 1.0.0-beta.1 < 1.0.0). Publique et statique : testable sans HTTP.
     *
     * @param array<int, mixed> $releases
     */
    public static function choisirPlusRecente(array $releases): ?array
    {
        $meilleure = null;
        $meilleureVersion = null;

        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || empty($release['tag_name'])) {
                continue;
            }
            $version = ltrim((string) $release['tag_name'], 'v');
            // Ignorer les tags non numériques (ex. « latest », « doc »)
            if (!preg_match('/^\d+\.\d+/', $version)) {
                continue;
            }
            if ($meilleureVersion === null
                || version_compare($version, $meilleureVersion, '>')) {
                $meilleure = $release;
                $meilleureVersion = $version;
            }
        }

        return $meilleure;
    }

    /**
     * Appel HTTP GET JSON vers l'API GitHub. Retourne null en cas
     * d'échec (réseau, HTTP >= 400, JSON inattendu).
     */
    private function fetchJson(string $url): ?array
    {
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

        // Vérifier le code HTTP
        $httpCode = $this->extractHttpCode($http_response_header ?? []);
        if ($httpCode >= 400) {
            error_log(sprintf(
                '[GitHubReleaseProvider] GitHub API HTTP %d for %s',
                $httpCode,
                $url
            ));
            return null;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            error_log(sprintf(
                '[GitHubReleaseProvider] Unexpected GitHub API response: %s',
                substr($data, 0, 300)
            ));
            return null;
        }

        return $decoded;
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

    /**
     * Le cache est propre au canal : une instance qui bascule en
     * « beta » ne doit pas relire le cache « stable » (qui masquerait
     * les pré-releases jusqu'à expiration).
     */
    private function cacheFileEffectif(): string
    {
        return $this->channel === 'stable'
            ? $this->cacheFile
            : $this->cacheFile . '.' . $this->channel;
    }

    private function isCacheFresh(): bool
    {
        $fichier = $this->cacheFileEffectif();
        return is_file($fichier)
            && (time() - filemtime($fichier) < $this->cacheTtl);
    }

    /**
     * @return array{tag:string,zip_url:string,body:string}|null
     */
    private function readCache(): ?array
    {
        $raw = @file_get_contents($this->cacheFileEffectif());
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
        $fichier = $this->cacheFileEffectif();
        $dir = dirname($fichier);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($fichier, json_encode($release));
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