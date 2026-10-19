<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit C1 bis] Tests de non-régression sur les protections
 * .htaccess.
 *
 * Si le DocumentRoot Apache pointe sur la racine du projet (et non sur
 * public/), aucun fichier sensible ne doit être servi. Ces tests ne
 * vérifient pas le comportement d'Apache (pas de test d'intrusion
 * dynamique) mais la présence des directives de protection dans le
 * dépôt — une suppression accidentelle doit casser le build.
 */
final class HtaccessProtectionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testRootHtaccessDeniesEverything(): void
    {
        $file = self::ROOT . '/.htaccess';
        self::assertFileExists($file);
        $content = file_get_contents($file);
        self::assertStringContainsString('Require all denied', $content);
        // Les fichiers .env* doivent être explicitement interdits.
        self::assertStringContainsString('\.env', $content);
    }

    /**
     * @dataProvider deniedDirectoriesProvider
     */
    public function testSensitiveDirectoriesAreDenied(string $dir): void
    {
        $file = self::ROOT . '/' . $dir . '/.htaccess';
        self::assertFileExists($file, "Le dossier $dir doit contenir un .htaccess de protection");
        self::assertStringContainsString('Require all denied', file_get_contents($file));
    }

    public static function deniedDirectoriesProvider(): array
    {
        return [
            'setup'      => ['setup'],
            'bin'        => ['bin'],
            'migrations' => ['migrations'],
            'tests'      => ['tests'],
        ];
    }

    public function testPublicHtaccessGrantsAccess(): void
    {
        // public/ est le seul docroot légitime : il doit explicitement
        # ré-autoriser l'accès (le .htaccess racine interdit tout).
        $file = self::ROOT . '/public/.htaccess';
        self::assertFileExists($file);
        self::assertStringContainsString('Require all granted', file_get_contents($file));
    }
}
