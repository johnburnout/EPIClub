<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAppUpdateController;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * [VAGUE 10] Tests Unit d'AppUpdateController.
 *
 * Le GitHubReleaseProviderInterface est mocké, ce qui permet de tester
 * index() sans dépendre du réseau.
 *
 * ⚠️ Session mockée sans user['id'] → évite updateLastActivity() → PDO.
 */
final class AppUpdateControllerTest extends TestCase
{
    public function testIndexRendersUpdateAvailableWhenNewerReleaseExists(): void
    {
        $provider = $this->makeProviderMock();
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag'     => 'v0.99.0',
                'zip_url' => 'https://example.com/zip',
                'body'    => 'Release notes for v0.99.0',
            ]);

        $controller = $this->makeControllerWith($provider);

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString('v0.99.0', $content);
    }

    public function testIndexAcceptsReleaseWithoutBody(): void
    {
        // ⚠️ Fix #45 : une Release avec body vide ne doit PAS
        // provoquer le message « Impossible de contacter GitHub. »
        $provider = $this->makeProviderMock();
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag'     => 'v0.99.0',
                'zip_url' => 'https://example.com/zip',
                'body'    => '',
            ]);

        $controller = $this->makeControllerWith($provider);

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString('v0.99.0', $content);
        self::assertStringNotContainsString('Impossible de contacter GitHub', $content);
    }

    public function testIndexRendersErrorWhenProviderReturnsNull(): void
    {
        $provider = $this->makeProviderMock();
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn(null);

        $controller = $this->makeControllerWith($provider);

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString('Impossible de contacter GitHub', $content);
    }

    public function testIndexReturns200WhenSameVersion(): void
    {
        $currentVersion = trim(file_get_contents(__DIR__ . '/../../../version.txt'));

        $provider = $this->makeProviderMock();
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag'     => 'v' . $currentVersion,
                'zip_url' => 'https://example.com/zip',
                'body'    => 'Same version',
            ]);

        $controller = $this->makeControllerWith($provider);

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // [SÉCURITÉ — audit C1] Rejet des archives malveillantes
    // ==================================================================

    public function testPerformRejectsMaliciousZip(): void
    {
        // Zip contenant une entrée « zip slip » : ../public/shell.php
        $zipPath = tempnam(sys_get_temp_dir(), 'zip_test_');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('../public/shell.php', '<?php echo "pwned";');
        $zip->addFromString('EPIClub-1.2.3/version.txt', 'v9.9.9');
        $zip->close();
        $maliciousZip = file_get_contents($zipPath);
        unlink($zipPath);

        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag'     => 'v9.9.9',
            'zip_url' => 'https://example.com/malicious.zip',
            'body'    => 'Malicious release',
        ]);
        $provider->method('downloadUrl')->willReturn($maliciousZip);

        $controller = $this->makeControllerWith($provider);

        $request = Request::create('/admin/update/perform', 'POST', [
            'csrf_token' => 'token_test',
        ]);
        $response = $controller->perform($request);

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString('rejeté', $content);
        // L'extraction ne doit pas avoir eu lieu.
        self::assertDirectoryDoesNotExist(
            __DIR__ . '/../../../var/tmp/update/extracted'
        );
    }

    public function testPerformRejectsZipWithAbsolutePath(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip_test_');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('/etc/cron.d/backdoor', 'cron content');
        $zip->close();
        $maliciousZip = file_get_contents($zipPath);
        unlink($zipPath);

        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag'     => 'v9.9.9',
            'zip_url' => 'https://example.com/malicious.zip',
            'body'    => 'Malicious release',
        ]);
        $provider->method('downloadUrl')->willReturn($maliciousZip);

        $controller = $this->makeControllerWith($provider);

        $request = Request::create('/admin/update/perform', 'POST', [
            'csrf_token' => 'token_test',
        ]);
        $response = $controller->perform($request);

        self::assertStringContainsString('rejeté', $response->getContent());
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeControllerWith(
        GitHubReleaseProviderInterface $provider,
    ): TestableAppUpdateController {
        return new TestableAppUpdateController(
            $this->makeSession(),
            $provider,
        );
    }

    private function makeProviderMock(): GitHubReleaseProviderInterface
    {
        return (new MockBuilder($this, GitHubReleaseProviderInterface::class))
            ->getMock();
    }

    private function makeSession(): Session
    {
        $session = (new MockBuilder($this, Session::class))->getMock();
        $session->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'csrf_token' ? 'token_test' : null
        );
        $session->method('has')->willReturn(false);
        $session->method('isAuthenticated')->willReturn(true);
        $session->method('isGranted')->willReturn(true);
        return $session;
    }
}