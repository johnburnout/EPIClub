<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Cli;

use Epiclub\Engine\Cli\ResumeSmtp;
use PHPUnit\Framework\TestCase;

/** Résumé de la configuration SMTP pour bin/infos.php et bin/tester-smtp.php. */
final class ResumeSmtpTest extends TestCase
{
    public function testResumeSansMotDePasse(): void
    {
        $resume = ResumeSmtp::depuisConfig([
            'MAILER_DSN'  => 'smtp://epiclub%40club-x.fr:p%40ss%3Aw0rd@smtp.club-x.fr:587',
            'MAILER_FROM' => 'epiclub@club-x.fr',
            'MAILER_NAME' => 'Club X',
        ]);

        self::assertSame([
            'hote'           => 'smtp.club-x.fr',
            'port'           => 587,
            'protocole'      => 'smtp',
            'utilisateur'    => 'epiclub@club-x.fr',
            'expediteur'     => 'epiclub@club-x.fr',
            'nom_expediteur' => 'Club X',
        ], $resume);
        self::assertStringNotContainsString('w0rd', (string) json_encode($resume));
    }

    public function testSansPortNiIdentifiant(): void
    {
        $resume = ResumeSmtp::depuisConfig(['MAILER_DSN' => 'smtps://smtp.exemple.fr', 'MAILER_FROM' => '']);

        self::assertNotNull($resume);
        self::assertSame('smtps', $resume['protocole']);
        self::assertNull($resume['port']);
        self::assertNull($resume['utilisateur']);
        self::assertNull($resume['expediteur']);
    }

    public function testSmtpNonConfigure(): void
    {
        self::assertNull(ResumeSmtp::depuisConfig([]));
        self::assertNull(ResumeSmtp::depuisConfig(['MAILER_DSN' => '']));
        self::assertNull(ResumeSmtp::depuisConfig(['MAILER_DSN' => 'pas un dsn']));
    }
}
