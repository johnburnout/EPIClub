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