<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\GitHubReleaseProvider;
use PHPUnit\Framework\TestCase;

/**
 * [VAGUE 10] Tests Unit de GitHubReleaseProvider.
 *
 * ⚠️ Les appels HTTP (API GitHub + downloadUrl) ne sont PAS testés ici
 *    car ils nécessitent le réseau. Ces tests couvrent :
 *    - le cache TTL (lecture/écriture)
 *    - le parsing de la réponse API
 *    - l'acceptation des Releases sans body (fix #45)
 *    - la gestion des erreurs (JSON invalide, HTTP 404, etc.)
 */
final class GitHubReleaseProviderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/epiclub_gh_provider_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // ==================================================================
    // Cache
    // ==================================================================

    public function testGetLatestReleaseReturnsCachedValueWhenFresh(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        file_put_contents($cacheFile, json_encode([
            'tag'     => 'v0.99.0',
            'zip_url' => 'https://example.com/zip',
            'body'    => 'Cached release',
        ]));

        $provider = new GitHubReleaseProvider('fake/repo', $cacheFile, 3600);

        $result = $provider->getLatestRelease();

        self::assertSame('v0.99.0', $result['tag']);
        self::assertSame('https://example.com/zip', $result['zip_url']);
        self::assertSame('Cached release', $result['body']);
    }

    public function testGetLatestReleaseIgnoresExpiredCache(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        file_put_contents($cacheFile, json_encode([
            'tag'     => 'v0.99.0',
            'zip_url' => 'https://example.com/zip',
            'body'    => 'Expired',
        ]));
        // Forcer l'expiration : mtime dans le passé
        touch($cacheFile, time() - 7200);

        // Cache TTL = 1h → expiré → tentative HTTP (échouera car repo fake)
        $provider = new GitHubReleaseProvider('fake/repo-does-not-exist-999', $cacheFile, 3600);

        $result = $provider->getLatestRelease();

        // ⚠️ L'appel HTTP échoue → null. On ne peut PAS vérifier le
        // contenu de la requête car le réseau n'est pas mockable ici.
        // Ce test vérifie juste que le cache expiré est bien ignoré.
        self::assertNull($result);
    }

    public function testGetLatestReleaseAcceptsCacheWithoutBody(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        // Cache sans clé "body" → doit être accepté avec body=''
        file_put_contents($cacheFile, json_encode([
            'tag'     => 'v0.99.0',
            'zip_url' => 'https://example.com/zip',
        ]));

        $provider = new GitHubReleaseProvider('fake/repo', $cacheFile, 3600);

        $result = $provider->getLatestRelease();

        self::assertSame('v0.99.0', $result['tag']);
        self::assertSame('', $result['body']);   // ← fix #45 : pas de null
    }

    public function testGetLatestReleaseIgnoresCorruptedCache(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        file_put_contents($cacheFile, 'not-json-at-all');

        $provider = new GitHubReleaseProvider('fake/repo-does-not-exist-999', $cacheFile, 3600);

        // Cache corrompu → tentative HTTP → échoue → null
        $result = $provider->getLatestRelease();

        self::assertNull($result);
    }

    // ==================================================================
    // Canal beta : choisirPlusRecente
    // ==================================================================

    public function testChoisirPlusRecentePrendLaBetaParmiStableEtBeta(): void
    {
        $releases = [
            ['tag_name' => 'v0.32.0', 'zipball_url' => 'https://x/032', 'draft' => false],
            ['tag_name' => 'v1.0.0-beta.1', 'zipball_url' => 'https://x/b1', 'draft' => false, 'prerelease' => true],
        ];

        $choix = GitHubReleaseProvider::choisirPlusRecente($releases);

        self::assertSame('v1.0.0-beta.1', $choix['tag_name']);
    }

    public function testChoisirPlusRecentePrefereLaStableAMemeVersion(): void
    {
        // Semver : 1.0.0 > 1.0.0-beta.2
        $releases = [
            ['tag_name' => 'v1.0.0-beta.2', 'zipball_url' => 'https://x/b2', 'draft' => false, 'prerelease' => true],
            ['tag_name' => 'v1.0.0', 'zipball_url' => 'https://x/100', 'draft' => false],
        ];

        $choix = GitHubReleaseProvider::choisirPlusRecente($releases);

        self::assertSame('v1.0.0', $choix['tag_name']);
    }

    public function testChoisirPlusRecenteIgnoreDraftsEtTagsNonNumeriques(): void
    {
        $releases = [
            ['tag_name' => 'v9.9.9', 'zipball_url' => 'https://x/999', 'draft' => true],
            ['tag_name' => 'latest', 'zipball_url' => 'https://x/l', 'draft' => false],
            ['tag_name' => 'doc', 'zipball_url' => 'https://x/d', 'draft' => false],
            ['tag_name' => 'v0.32.0', 'zipball_url' => 'https://x/032', 'draft' => false],
        ];

        $choix = GitHubReleaseProvider::choisirPlusRecente($releases);

        self::assertSame('v0.32.0', $choix['tag_name']);
    }

    public function testChoisirPlusRecenteRetourneNullSurListeVide(): void
    {
        self::assertNull(GitHubReleaseProvider::choisirPlusRecente([]));
    }

    // ==================================================================
    // Canal beta : cache propre au canal
    // ==================================================================

    public function testCanalBetaLitSonPropreCache(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        // Cache du canal beta (suffixe .beta)
        file_put_contents($cacheFile . '.beta', json_encode([
            'tag'     => 'v1.0.0-beta.9',
            'zip_url' => 'https://example.com/beta-zip',
            'body'    => 'Beta release',
        ]));

        $provider = new GitHubReleaseProvider('fake/repo', $cacheFile, 3600, 'beta');

        $result = $provider->getLatestRelease();

        self::assertSame('v1.0.0-beta.9', $result['tag']);
    }

    public function testCanalInconnuRetombeSurStable(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        // Seul le cache beta existe : un canal normalisé en « stable »
        // ne doit PAS le lire (HTTP échouera sur le faux repo → null).
        file_put_contents($cacheFile . '.beta', json_encode([
            'tag'     => 'v1.0.0-beta.9',
            'zip_url' => 'https://example.com/beta-zip',
        ]));

        $provider = new GitHubReleaseProvider(
            'fake/repo-does-not-exist-999',
            $cacheFile,
            3600,
            'canal-bidon'
        );

        self::assertNull($provider->getLatestRelease());
    }

    public function testCanalStableIgnoreLeCacheBeta(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        file_put_contents($cacheFile . '.beta', json_encode([
            'tag'     => 'v1.0.0-beta.9',
            'zip_url' => 'https://example.com/beta-zip',
        ]));

        $provider = new GitHubReleaseProvider(
            'fake/repo-does-not-exist-999',
            $cacheFile,
            3600,
            'stable'
        );

        // Le cache .beta ne doit pas être lu → HTTP → échec → null
        self::assertNull($provider->getLatestRelease());
    }

    // ==================================================================
    // downloadUrl
    // ==================================================================

    public function testDownloadUrlThrowsWhenFileGetContentsAndCurlFail(): void
    {
        if (!function_exists('curl_version')) {
            self::markTestSkipped('cURL non disponible');
        }

        $provider = new GitHubReleaseProvider('fake/repo', $this->tmpDir . '/cache.json');

        // URL invalide → cURL échoue avec une erreur
        $this->expectException(\RuntimeException::class);

        $provider->downloadUrl('https://invalid.example.invalid/never-resolves');
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}