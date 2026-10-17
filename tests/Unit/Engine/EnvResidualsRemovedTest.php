<?php

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAbstractController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [#110] Derniers résiduels `$_ENV` hors bootstrap — AbstractController
 * (PUBLIC_PATH) et TwigRenderer (APP_ENV). La config doit transiter par
 * ConfigProvider (itéré par ConfigFile::defaultPath()).
 */
final class EnvResidualsRemovedTest extends TestCase
{
    private string $abstractControllerSource;
    private string $twigRendererSource;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->abstractControllerSource = file_get_contents($root . '/app/Engine/AbstractController.php');
        $this->twigRendererSource = file_get_contents($root . '/app/Engine/TwigRenderer.php');
    }

    public function testAbstractControllerNoLongerReadsEnv(): void
    {
        self::assertStringNotContainsString(
            '$_ENV',
            $this->abstractControllerSource,
            'AbstractController doit lire la config via ConfigProvider, plus $_ENV.'
        );
    }

    public function testTwigRendererNoLongerReadsEnv(): void
    {
        self::assertStringNotContainsString(
            '$_ENV',
            $this->twigRendererSource,
            'TwigRenderer doit lire APP_ENV via ConfigProvider, plus $_ENV.'
        );
    }

    public function testGetPublicPathPrefersConfigProviderValue(): void
    {
        // Rouge avant #110 : getPublicPath() lisait $_ENV et ignorait le
        // ConfigProvider injecté.
        $tmp = $this->makeTempConfig(['PUBLIC_PATH' => '/var/custom/public']);
        try {
            $controller = (new TestableAbstractController($this->makeSession()))
                ->withConfigProvider(new ConfigProvider($tmp));
            self::assertSame('/var/custom/public', $controller->exposeGetPublicPath());
            // Iso-comportement : seul le slash de tête du sous-chemin est consommé.
            self::assertSame('/var/custom/public/images/', $controller->exposeGetPublicPath('/images/'));
        } finally {
            unlink($tmp);
        }
    }

    public function testGetPublicPathFallsBackToProjectPublicDir(): void
    {
        $tmp = $this->makeTempConfig([]);
        try {
            $controller = (new TestableAbstractController($this->makeSession()))
                ->withConfigProvider(new ConfigProvider($tmp));
            self::assertSame(
                dirname(__DIR__, 3) . '/public',
                $controller->exposeGetPublicPath()
            );
        } finally {
            unlink($tmp);
        }
    }

    /** @param array<string, mixed> $config */
    private function makeTempConfig(array $config): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epiclub-env-');
        file_put_contents($tmp, '<?php return ' . var_export($config, true) . ';');
        return $tmp;
    }

    private function makeSession(): Session
    {
        // ⚠️ Session sans user : évite updateLastActivity() → PDO
        return new Session(new MockArraySessionStorage());
    }
}
