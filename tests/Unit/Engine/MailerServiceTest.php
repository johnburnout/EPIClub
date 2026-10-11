<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\MailerService;
use Epiclub\Exception\MailDeliveryException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

final class MailerServiceTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        $this->envFile = __DIR__ . '/../../Fixtures/env.test.php';
        if (!is_file($this->envFile)) {
            self::markTestSkipped('Fixture env.test.php introuvable.');
        }
    }

    // ==================================================================
    // Cas nominal : DSN valide
    // ==================================================================

    /**
     * Avec un DSN valide, sendEmail() ne doit pas throw — il utilise
     * un transport null:// qui accepte tout sans envoyer réellement.
     *
     * Note : on utilise le transport "null://null" de Symfony, qui
     * accepte les emails sans rien faire. C'est le transport de test
     * officiel, recommandé par la doc Symfony Mailer.
     */
    public function testSendEmailWithNullTransportSucceeds(): void
    {
        $config = $this->configWithDsn('null://null');
        $service = new MailerService($config);

        $email = (new Email())
            ->from('test@example.com')
            ->to('dest@example.com')
            ->subject('Test')
            ->text('Body');

        // Ne doit pas throw
        $service->sendEmail($email);
        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // Cas DSN absent → MailDeliveryException
    // ==================================================================

    public function testSendEmailThrowsWhenDsnIsMissing(): void
    {
        $config = $this->configWithDsn('');  // DSN vide
        $service = new MailerService($config);

        $email = (new Email())
            ->from('test@example.com')
            ->to('dest@example.com')
            ->subject('Test')
            ->text('Body');

        $this->expectException(MailDeliveryException::class);
        $service->sendEmail($email);
    }

    // ==================================================================
    // Cas DSN malformé → MailDeliveryException
    // ==================================================================

    public function testSendEmailThrowsWhenDsnIsMalformed(): void
    {
        $config = $this->configWithDsn('not-a-valid-dsn');
        $service = new MailerService($config);

        $email = (new Email())
            ->from('test@example.com')
            ->to('dest@example.com')
            ->subject('Test')
            ->text('Body');

        $this->expectException(MailDeliveryException::class);
        $service->sendEmail($email);
    }

    // ==================================================================
    // Lazy : le DSN n'est pas lu au constructeur
    // ==================================================================

    /**
     * Le constructeur ne doit PAS throw même avec un DSN vide —
     * la lecture est lazy, au premier sendEmail().
     *
     * Iso-comportement avec l'implémentation actuelle.
     */
    public function testConstructorDoesNotThrowWithEmptyDsn(): void
    {
        $config = $this->configWithDsn('');

        // Ne doit pas throw
        $service = new MailerService($config);
        self::assertInstanceOf(MailerService::class, $service);
    }

    // ==================================================================
    // Helper : ConfigProvider sur fichier temporaire
    // ==================================================================

    /**
     * Crée un ConfigProvider avec un DSN donné, dans un fichier
     * temporaire — pour ne pas toucher à la fixture env.test.php
     * qui n'a pas (forcément) de MAILER_DSN.
     */
    private function configWithDsn(string $dsn): ConfigProvider
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'mailer_test_') . '.php';
        $content = "<?php\nreturn " . var_export(['MAILER_DSN' => $dsn], true) . ";\n";
        file_put_contents($tmpFile, $content);

        // Cleanup automatique en fin de test via shutdown
        register_shutdown_function(function () use ($tmpFile) {
            @unlink($tmpFile);
        });

        return new ConfigProvider($tmpFile);
    }
}