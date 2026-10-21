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

    /**
     * [v1.6.2] Durée de mise en cache d'une release dont l'archive complète
     * n'est pas encore attachée (CI en cours) : courte, pour proposer la
     * mise à jour peu après la fin de la CI sans interroger GitHub à
     * chaque affichage.
     */
    public const TTL_ARCHIVE_EN_PREPARATION = 120;

    private readonly string $channel;
    private readonly ?string $token;

    /**
     * @param ?string $token      [v1.6.2] Jeton GitHub facultatif (GITHUB_TOKEN) :
     *                            limite de l'API portée de 60 à 5 000 requêtes/heure.
     *                            Envoyé UNIQUEMENT à api.github.com.
     * @param int     $failureTtl [v1.6.2] Après un échec (limite atteinte, réseau…),
     *                            GitHub n'est pas réinterrogé pendant ce délai (s).
     */
    public function __construct(
        private readonly string $repo,
        private readonly string $cacheFile,
        private readonly int $cacheTtl = 3600,
        string $channel = 'stable',
        ?string $token = null,
        private readonly int $failureTtl = 600,
    ) {
        $this->channel = in_array($channel, self::CANAUX, true) ? $channel : 'stable';
        $token = $token !== null ? trim($token) : null;
        $this->token = $token !== null && $token !== '' && !preg_match('/[\r\n]/', $token) ? $token : null;
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

        // 2. [v1.6.2] Échec récent : on n'insiste pas (chaque requête
        //    refusée consomme le quota de l'API pendant le blocage).
        if ($this->isEchecRecent()) {
            return null;
        }

        // 3. Appel HTTP
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

        if (!is_array($release)) {
            $this->marquerEchec();
            return null;
        }

        $result = self::construireResultat($release);
        if ($result === null) {
            $this->marquerEchec();
            return null;
        }

        // [v1.2.0-beta.2 / v1.6.2] Une release dont l'archive complète
        // n'est pas encore attachée (CI en cours) est mise en cache
        // brièvement (TTL_ARCHIVE_EN_PREPARATION, cf. isCacheFresh()).
        $this->effacerEchec();
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
    /**
     * [audit 2026-10-19 F1] Hôtes autorisés pour un téléchargement de
     * mise à jour (URL initiale ; les redirections restent chez GitHub).
     */
    public const HOTES_AUTORISES = [
        'github.com',
        'api.github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    /**
     * [audit 2026-10-19 F1] Construit le résultat à partir d'une release
     * de l'API GitHub.
     *
     * Priorité à l'archive COMPLÈTE publiée par la CI
     * (`epiclub-<tag>.zip`, vendor/ inclus) et à sa somme de contrôle
     * (`epiclub-<tag>.zip.sha256`). À défaut (anciennes releases), repli
     * sur le zipball des sources : composer devra alors être disponible.
     *
     * @return array{tag:string,zip_url:string,body:string,bundle:bool,sha256_url:?string}|null
     */
    public static function construireResultat(array $release): ?array
    {
        if (empty($release['tag_name']) || !is_string($release['tag_name'])) {
            return null;
        }
        $tag = $release['tag_name'];

        $zipUrl = null;
        $shaUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            if (!is_array($asset) || !isset($asset['name'], $asset['browser_download_url'])) {
                continue;
            }
            if ($asset['name'] === 'epiclub-' . $tag . '.zip') {
                $zipUrl = (string) $asset['browser_download_url'];
            } elseif ($asset['name'] === 'epiclub-' . $tag . '.zip.sha256') {
                $shaUrl = (string) $asset['browser_download_url'];
            }
        }

        $bundle = $zipUrl !== null;
        if (!$bundle) {
            if (empty($release['zipball_url'])) {
                return null;
            }
            $zipUrl = (string) $release['zipball_url'];
            $shaUrl = null;
        }

        return [
            'tag'        => $tag,
            'zip_url'    => $zipUrl,
            // ⚠️ FIX #45 : accepte body null (Release sans description)
            'body'       => (string) ($release['body'] ?? ''),
            'bundle'     => $bundle,
            'sha256_url' => $shaUrl,
        ];
    }

    /**
     * [audit 2026-10-19 F1/S3] Refuse toute URL qui n'est pas en HTTPS
     * vers GitHub (file://, php://, http://, hôte tiers…) — y compris si
     * le cache local a été altéré.
     *
     * @throws \RuntimeException
     */
    public static function assertUrlDeConfiance(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || !in_array($host, self::HOTES_AUTORISES, true)
            || isset($parts['user']) || isset($parts['port'])
        ) {
            throw new \RuntimeException('URL de mise à jour refusée : ' . substr($url, 0, 120));
        }
    }

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
    /**
     * En-têtes HTTP des appels à l'API. Le jeton n'est ajouté que pour
     * une URL HTTPS vers api.github.com (jamais vers un autre hôte).
     */
    public static function entetesApi(string $url, ?string $token): string
    {
        $entetes = "User-Agent: EPIClub-Update\r\n"
                 . "Accept: application/vnd.github.v3+json\r\n";

        $parts = parse_url($url);
        $versApi = strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'api.github.com';

        if ($token !== null && $token !== '' && $versApi) {
            $entetes .= 'Authorization: Bearer ' . $token . "\r\n";
        }
        return $entetes;
    }

    private function fetchJson(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'header' => self::entetesApi($url, $this->token),
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
        self::assertUrlDeConfiance($url);

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
        clearstatcache(true, $fichier);
        if (!is_file($fichier)) {
            return false;
        }
        $age = time() - filemtime($fichier);
        if ($age >= $this->cacheTtl) {
            return false;
        }

        // [v1.6.2] Archive pas encore attachée : cache bref seulement.
        $cached = json_decode((string) @file_get_contents($fichier), true);
        if (is_array($cached) && array_key_exists('bundle', $cached) && $cached['bundle'] === false) {
            return $age < self::TTL_ARCHIVE_EN_PREPARATION;
        }
        return true;
    }

    // ------------------------------------------------------------------
    // [v1.6.2] Mémorisation des échecs (limite de l'API, réseau…)
    // ------------------------------------------------------------------

    private function fichierEchec(): string
    {
        return $this->cacheFileEffectif() . '.echec';
    }

    private function isEchecRecent(): bool
    {
        $fichier = $this->fichierEchec();
        clearstatcache(true, $fichier);
        return $this->failureTtl > 0
            && is_file($fichier)
            && (time() - filemtime($fichier) < $this->failureTtl);
    }

    private function marquerEchec(): void
    {
        $fichier = $this->fichierEchec();
        $dir = dirname($fichier);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @touch($fichier);
    }

    private function effacerEchec(): void
    {
        if (is_file($this->fichierEchec())) {
            @unlink($this->fichierEchec());
        }
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
            'tag'        => (string) $cached['tag'],
            'zip_url'    => (string) $cached['zip_url'],
            'body'       => (string) ($cached['body'] ?? ''),
            'bundle'     => (bool) ($cached['bundle'] ?? false),
            'sha256_url' => isset($cached['sha256_url']) && is_string($cached['sha256_url'])
                ? $cached['sha256_url'] : null,
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