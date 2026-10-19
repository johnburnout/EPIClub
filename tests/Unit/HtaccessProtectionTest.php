<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('deniedDirectoriesProvider')]
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

    /**
     * [SÉCURITÉ — audit 2026-10-19 S1] Le dossier d'images téléversées
     * ne doit servir aucun fichier interprétable (PHP, HTML, SVG, JS).
     */
    #[DataProvider('forbiddenImageDirFilesProvider')]
    public function testPublicImagesHtaccessDeniesExecutableFiles(string $filename): void
    {
        $file = self::ROOT . '/public/images/.htaccess';
        self::assertFileExists($file, 'public/images/ doit contenir un .htaccess de protection');
        $content = file_get_contents($file);
        self::assertStringContainsString('Require all denied', $content);

        self::assertSame(1, preg_match('/<FilesMatch "([^"]+)">/', $content, $m), 'Bloc FilesMatch introuvable');
        self::assertSame(1, preg_match('/' . str_replace('/', '\\/', $m[1]) . '/', $filename),
            "$filename doit être refusé par public/images/.htaccess");
    }

    public static function forbiddenImageDirFilesProvider(): array
    {
        return [
            'php'          => ['shell.php'],
            'php majuscule'=> ['SHELL.PHP'],
            'phtml'        => ['x.phtml'],
            'phar'         => ['x.phar'],
            'html'         => ['xss.html'],
            'htm'          => ['xss.htm'],
            'svg'          => ['xss.svg'],
            'js'           => ['x.js'],
            'htaccess'     => ['.htaccess'],
        ];
    }

    public function testPublicImagesHtaccessStillServesImages(): void
    {
        $content = file_get_contents(self::ROOT . '/public/images/.htaccess');
        preg_match('/<FilesMatch "([^"]+)">/', $content, $m);
        foreach (['a.webp', 'a.jpg', 'a.png', 'a.gif', 'Harnais-6a411e6642b19.webp'] as $image) {
            self::assertSame(0, preg_match('/' . str_replace('/', '\\/', $m[1]) . '/', $image),
                "$image doit rester accessible");
        }
    }
}
