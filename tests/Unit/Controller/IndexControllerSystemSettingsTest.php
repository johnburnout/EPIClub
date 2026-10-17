<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableIndexController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * [#101 — Dette 16 soldée]
 *
 * systemSettings() lisait la config SMTP via
 * `getenv('MAILER_DSN') ?: $_ENV['MAILER_DSN'] ?? ''`, contournant
 * ConfigProvider (source unique de vérité depuis la Vague 12, #50).
 * La page affichait potentiellement des valeurs différentes de celles
 * réellement utilisées (lecture process/env au lieu de .env.local.php).
 */
final class IndexControllerSystemSettingsTest extends TestCase
{
    private string $envFixture;

    protected function setUp(): void
    {
        $this->envFixture = sys_get_temp_dir() . '/epiclub-101-index-' . uniqid() . '.php';
        file_put_contents($this->envFixture, '<?php return ' . var_export([
            'MAILER_DSN'  => 'smtp://svc@mail.example.org:587?encryption=tls',
            'MAILER_FROM' => 'epi@club.test',
            'MAILER_NAME' => 'EPIClub Test',
            'ROOT_URL'    => 'https://epi.example.fr',
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        if (is_file($this->envFixture)) {
            unlink($this->envFixture);
        }
    }

    public function testSystemSettingsRendersValuesFromConfigProvider(): void
    {
        $controller = new TestableIndexController(
            $this->mockSession(),
            configProviderMock: new ConfigProvider($this->envFixture),
        );

        $response = $controller->systemSettings(Request::create('/admin/system-settings', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        // DSN parsé : host / port / user / encryption
        self::assertStringContainsString('mail.example.org', $content);
        self::assertStringContainsString('587', $content);
        self::assertStringContainsString('svc', $content);
        self::assertStringContainsString('tls', $content);
        // Expéditeur + ROOT_URL
        self::assertStringContainsString('epi@club.test', $content);
        self::assertStringContainsString('EPIClub Test', $content);
        self::assertStringContainsString('https://epi.example.fr', $content);
    }

    public function testSystemSettingsFallsBackToEmptyValuesWhenConfigUnreadable(): void
    {
        // Simule un .env.local.php absent/illisible : la page doit
        // quand même s'afficher avec des champs vides (iso-comportement
        // avec getenv/$_ENV ?? '').
        $controller = new TestableIndexController(
            $this->mockSession(),
            configThrows: true,
        );

        $response = $controller->systemSettings(Request::create('/admin/system-settings', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('mail.example.org', $response->getContent());
    }

    private function mockSession(): Session
    {
        $session = $this->getMockBuilder(Session::class)->getMock();
        $session->method('get')->willReturn(null);
        $session->method('isAuthenticated')->willReturn(true);
        $session->method('isGranted')->willReturn(true);
        return $session;
    }
}
