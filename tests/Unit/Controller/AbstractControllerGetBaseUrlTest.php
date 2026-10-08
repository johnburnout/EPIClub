<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAbstractController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests Unit de AbstractController::getBaseUrl() (issue #50a).
 *
 * ⚠️ Politique transitoire : ROOT_URL prioritaire, fallback sur
 *    SERVER_NAME si absent (déprécié, sera supprimé en #50a-bis).
 */
final class AbstractControllerGetBaseUrlTest extends TestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    // ==================================================================
    // ROOT_URL présent
    // ==================================================================

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function rootUrlProvider(): array
    {
        return [
            'URL simple'                 => ['http://localhost:8000',   'http://localhost:8000'],
            'URL avec slash final'       => ['http://localhost:8000/',  'http://localhost:8000'],
            'URL avec plusieurs slashs'  => ['https://epiclub.fr//',    'https://epiclub.fr'],
            'HTTPS avec sous-chemin'     => ['https://example.com/app', 'https://example.com/app'],
        ];
    }

    #[DataProvider('rootUrlProvider')]
    public function testGetBaseUrlUsesRootUrl(
        string $rootUrl,
        string $expected,
    ): void {
        $controller = $this->makeControllerWith(['ROOT_URL' => $rootUrl]);

        self::assertSame($expected, $controller->exposeGetBaseUrl());
    }

    // ==================================================================
    // ROOT_URL absent → fallback SERVER_NAME
    // ==================================================================

    public function testGetBaseUrlFallsBackToServerNameWhenRootUrlMissing(): void
    {
        $_SERVER['SERVER_NAME'] = 'fallback.example.com';
        $_SERVER['HTTPS'] = 'on';

        try {
            $controller = $this->makeControllerWith([]);

            self::assertSame(
                'https://fallback.example.com',
                $controller->exposeGetBaseUrl()
            );
        } finally {
            unset($_SERVER['SERVER_NAME'], $_SERVER['HTTPS']);
        }
    }

    public function testGetBaseUrlFallsBackToServerNameWhenRootUrlEmpty(): void
    {
        $_SERVER['SERVER_NAME'] = 'fallback.example.com';
        unset($_SERVER['HTTPS']);

        try {
            $controller = $this->makeControllerWith(['ROOT_URL' => '']);

            self::assertSame(
                'http://fallback.example.com',
                $controller->exposeGetBaseUrl()
            );
        } finally {
            unset($_SERVER['SERVER_NAME'], $_SERVER['HTTPS']);
        }
    }

    public function testGetBaseUrlFallsBackToLocalhostWhenServerNameMissing(): void
    {
        unset($_SERVER['SERVER_NAME'], $_SERVER['HTTPS']);

        $controller = $this->makeControllerWith([]);

        self::assertSame('http://localhost', $controller->exposeGetBaseUrl());
    }

    public function testGetBaseUrlDoesNotUseHttpHostHeader(): void
    {
        // ⚠️ Le point clé de #50a : HTTP_HOST ne doit JAMAIS être utilisé.
        $_SERVER['HTTP_HOST'] = 'evil.example.com';
        $_SERVER['SERVER_NAME'] = 'legit.example.com';
        $_SERVER['HTTPS'] = 'on';

        try {
            $controller = $this->makeControllerWith([]);

            $result = $controller->exposeGetBaseUrl();

            self::assertSame('https://legit.example.com', $result);
            self::assertStringNotContainsString('evil', $result);
        } finally {
            unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'], $_SERVER['HTTPS']);
        }
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * @param array<string,mixed> $config
     */
    private function makeControllerWith(array $config): TestableAbstractController
    {
        $tmpFile = sys_get_temp_dir() . '/epiclub_absctrl_test_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($tmpFile, '<?php return ' . var_export($config, true) . ';');
        $this->tmpFiles[] = $tmpFile;

        $configProvider = new ConfigProvider($tmpFile);

        $controller = new TestableAbstractController($this->makeSession());
        $controller->withConfigProvider($configProvider);

        return $controller;
    }

    private function makeSession(): Session
    {
        // ⚠️ Session sans user : évite updateLastActivity() → PDO
        return new Session(new MockArraySessionStorage());
    }
}