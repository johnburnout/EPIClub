<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\GitHubReleaseProvider;
use PHPUnit\Framework\Attributes\DataProvider;
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
    // [audit 2026-10-19 F1] Archive complète publiée par la CI
    // ==================================================================

    public function testConstruireResultatPrefereLArchiveComplete(): void
    {
        $r = GitHubReleaseProvider::construireResultat([
            'tag_name'    => 'v1.2.0',
            'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.2.0',
            'body'        => null,
            'assets'      => [
                ['name' => 'epiclub-v1.1.0.zip', 'browser_download_url' => 'https://github.com/o/r/releases/download/v1.1.0/epiclub-v1.1.0.zip'],
                ['name' => 'epiclub-v1.2.0.zip.sha256', 'browser_download_url' => 'https://github.com/o/r/releases/download/v1.2.0/epiclub-v1.2.0.zip.sha256'],
                ['name' => 'epiclub-v1.2.0.zip', 'browser_download_url' => 'https://github.com/o/r/releases/download/v1.2.0/epiclub-v1.2.0.zip'],
            ],
        ]);

        self::assertSame('v1.2.0', $r['tag']);
        self::assertTrue($r['bundle']);
        self::assertSame('https://github.com/o/r/releases/download/v1.2.0/epiclub-v1.2.0.zip', $r['zip_url']);
        self::assertSame('https://github.com/o/r/releases/download/v1.2.0/epiclub-v1.2.0.zip.sha256', $r['sha256_url']);
        self::assertSame('', $r['body']);
    }

    public function testConstruireResultatReplieSurLeZipballSansAsset(): void
    {
        $r = GitHubReleaseProvider::construireResultat([
            'tag_name'    => 'v0.9.0',
            'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v0.9.0',
            'body'        => 'notes',
            'assets'      => [],
        ]);

        self::assertFalse($r['bundle']);
        self::assertNull($r['sha256_url']);
        self::assertSame('https://api.github.com/repos/o/r/zipball/v0.9.0', $r['zip_url']);
    }

    public function testConstruireResultatRetourneNullSiRienATelecharger(): void
    {
        self::assertNull(GitHubReleaseProvider::construireResultat(['tag_name' => 'v1.0.0']));
        self::assertNull(GitHubReleaseProvider::construireResultat(['zipball_url' => 'https://api.github.com/x']));
    }

    public function testLeCacheConserveLesChampsDeLArchiveComplete(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        file_put_contents($cacheFile, json_encode([
            'tag' => 'v1.2.0', 'zip_url' => 'https://github.com/a.zip', 'body' => '',
            'bundle' => true, 'sha256_url' => 'https://github.com/a.zip.sha256',
        ]));

        $r = (new GitHubReleaseProvider('fake/repo', $cacheFile, 3600))->getLatestRelease();

        self::assertTrue($r['bundle']);
        self::assertSame('https://github.com/a.zip.sha256', $r['sha256_url']);
    }

    #[DataProvider('urlsDeConfianceProvider')]
    public function testAssertUrlDeConfianceAccepteGitHub(string $url): void
    {
        GitHubReleaseProvider::assertUrlDeConfiance($url);
        $this->addToAssertionCount(1);
    }

    public static function urlsDeConfianceProvider(): array
    {
        return [
            'asset'   => ['https://github.com/johnburnout/EPIClub/releases/download/v1.0.0/epiclub-v1.0.0.zip'],
            'zipball' => ['https://api.github.com/repos/johnburnout/EPIClub/zipball/v1.0.0'],
            'codeload'=> ['https://codeload.github.com/johnburnout/EPIClub/legacy.zip/v1.0.0'],
        ];
    }

    #[DataProvider('urlsRefuseesProvider')]
    public function testAssertUrlDeConfianceRefuseLeReste(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        GitHubReleaseProvider::assertUrlDeConfiance($url);
    }

    public static function urlsRefuseesProvider(): array
    {
        return [
            'fichier local'      => ['file:///etc/passwd'],
            'wrapper php'        => ['php://filter/resource=/var/www/.env.local.php'],
            'http clair'         => ['http://github.com/a.zip'],
            'hôte tiers'         => ['https://evil.example/a.zip'],
            'sous-domaine piège' => ['https://github.com.evil.example/a.zip'],
            'identifiants'       => ['https://user@github.com/a.zip'],
            'port'               => ['https://github.com:8443/a.zip'],
            'vide'               => [''],
        ];
    }

    public function testDownloadUrlRefuseUnFichierLocalSansLeLire(): void
    {
        $provider = new GitHubReleaseProvider('fake/repo', $this->tmpDir . '/cache.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusée');
        $provider->downloadUrl('file://' . __FILE__);
    }

    // ==================================================================
    // [v1.6.2] Limite de l'API GitHub : échecs mémorisés, jeton facultatif
    // ==================================================================

    public function testUnEchecEstMemorisePourNePasReinterrogerGitHub(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        $provider = new GitHubReleaseProvider('fake/repo-does-not-exist-999', $cacheFile, 3600);

        self::assertNull($provider->getLatestRelease());
        self::assertFileExists($cacheFile . '.echec', 'échec mémorisé');
    }

    public function testEchecRecentAucunAppelHttp(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        touch($cacheFile . '.echec');
        $journal = $this->tmpDir . '/php-error.log';
        $ancien = ini_set('error_log', $journal);

        try {
            $r = (new GitHubReleaseProvider('fake/repo-does-not-exist-999', $cacheFile, 3600))->getLatestRelease();
        } finally {
            ini_set('error_log', (string) $ancien);
        }

        self::assertNull($r);
        self::assertFalse(
            is_file($journal) && str_contains((string) file_get_contents($journal), '[GitHubReleaseProvider]'),
            'aucune requête HTTP pendant le délai après échec'
        );
    }

    public function testEchecAncienOnReessaie(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        touch($cacheFile . '.echec', time() - 601);
        $journal = $this->tmpDir . '/php-error.log';
        $ancien = ini_set('error_log', $journal);

        try {
            (new GitHubReleaseProvider('fake/repo-does-not-exist-999', $cacheFile, 3600))->getLatestRelease();
        } finally {
            ini_set('error_log', (string) $ancien);
        }

        self::assertStringContainsString('[GitHubReleaseProvider]', (string) @file_get_contents($journal));
        self::assertGreaterThan(time() - 60, filemtime($cacheFile . '.echec'), 'échec re-daté');
    }

    public function testArchiveEnPreparationCacheBrefSeulement(): void
    {
        $cacheFile = $this->tmpDir . '/release.json';
        file_put_contents($cacheFile, json_encode([
            'tag' => 'v9.9.9', 'zip_url' => 'https://github.com/a.zip', 'bundle' => false,
        ]));
        $provider = new GitHubReleaseProvider('fake/repo-does-not-exist-999', $cacheFile, 3600);

        // Récent (< 2 min) : servi depuis le cache
        self::assertSame('v9.9.9', $provider->getLatestRelease()['tag'] ?? null);

        // Plus ancien : ignoré (HTTP → échec sur le faux dépôt → null)
        touch($cacheFile, time() - GitHubReleaseProvider::TTL_ARCHIVE_EN_PREPARATION - 1);
        self::assertNull($provider->getLatestRelease());
    }

    public function testJetonEnvoyeUniquementAApiGitHub(): void
    {
        $api = GitHubReleaseProvider::entetesApi('https://api.github.com/repos/o/r/releases', 'ghp_x');
        self::assertStringContainsString("Authorization: Bearer ghp_x\r\n", $api);

        foreach (['https://github.com/o/r/releases', 'http://api.github.com/x', 'https://evil.example/x'] as $url) {
            self::assertStringNotContainsString('Authorization', GitHubReleaseProvider::entetesApi($url, 'ghp_x'), $url);
        }
        self::assertStringNotContainsString('Authorization', GitHubReleaseProvider::entetesApi('https://api.github.com/x', null));
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