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
        $session->method('get')->willReturn(null);
        $session->method('has')->willReturn(false);
        $session->method('isAuthenticated')->willReturn(true);
        $session->method('isGranted')->willReturn(true);
        return $session;
    }
}