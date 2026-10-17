<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAppUpdateController;
use PHPUnit\Framework\TestCase;

/**
 * [#101 — Dette 17 soldée]
 *
 * AppUpdateController::perform() construisait le DSN PDO depuis
 * `$_ENV['DB_HOST']` etc. — dernier accès direct à $_ENV pour PDO.
 * La connexion migrations passe désormais par ConfigProvider
 * (source unique de vérité, cf. bootstrap qui alimente $_ENV depuis
 * le même fichier).
 */
final class AppUpdateDatabasePdoTest extends TestCase
{
    private string $envFixture;

    protected function setUp(): void
    {
        $this->envFixture = sys_get_temp_dir() . '/epiclub-101-update-' . uniqid() . '.php';
        file_put_contents($this->envFixture, '<?php return ' . var_export([
            'DB_HOST' => 'db.example.org',
            'DB_NAME' => 'epiclub_test',
            'DB_USER' => 'epi_user',
            'DB_PASS' => 's3cret',
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        if (is_file($this->envFixture)) {
            unlink($this->envFixture);
        }
    }

    public function testDatabaseDsnIsBuiltFromConfigProvider(): void
    {
        $controller = $this->makeController();

        self::assertSame(
            'mysql:host=db.example.org;dbname=epiclub_test;charset=utf8mb4',
            $controller->databaseDsnForTest()
        );
    }

    public function testDatabasePdoUsesConfigProviderCredentials(): void
    {
        $controller = $this->makeController();

        try {
            $controller->databasePdoForTest();
            self::fail('databasePdo() doit court-circuiter la connexion en test.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('court-circuitée', $e->getMessage());
        }

        self::assertSame(
            [
                'dsn'  => 'mysql:host=db.example.org;dbname=epiclub_test;charset=utf8mb4',
                'user' => 'epi_user',
                'pass' => 's3cret',
            ],
            $controller->capturedPdoArgs
        );
    }

    private function makeController(): TestableAppUpdateController
    {
        $provider = $this->getMockBuilder(GitHubReleaseProviderInterface::class)->getMock();
        $session = $this->getMockBuilder(Session::class)->getMock();
        $session->method('get')->willReturn(null);
        $session->method('isAuthenticated')->willReturn(true);
        $session->method('isGranted')->willReturn(true);

        return new TestableAppUpdateController(
            $session,
            $provider,
            new ConfigProvider($this->envFixture),
        );
    }
}
