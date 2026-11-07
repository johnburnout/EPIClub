<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\DroitsFichiers;
use Epiclub\Process\MiseAJourService;
use PHPUnit\Framework\TestCase;

/**
 * [2026-11-07] Restauration et mise à jour lancées en CLI avec umask 077 :
 * les fichiers écrits restent lisibles par le serveur web (photos en 403
 * sinon).
 */
final class DroitsFichiersTest extends TestCase
{
    private string $racine;
    private int $umask;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir() . '/epiclub-droits-' . bin2hex(random_bytes(4));
        mkdir($this->racine, 0777);
        $this->umask = umask(0077);
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        exec('rm -rf ' . escapeshellarg($this->racine));
    }

    private static function droits(string $chemin): int
    {
        clearstatcache(true, $chemin);
        return fileperms($chemin) & 0777;
    }

    public function testCreerDossierDonneAuMoins0755MemeEnUmask077(): void
    {
        self::assertTrue(DroitsFichiers::creerDossier($this->racine . '/a/b/c'));

        foreach (['/a', '/a/b', '/a/b/c'] as $d) {
            self::assertSame(0755, self::droits($this->racine . $d), $d);
        }
    }

    public function testFichierCopieLisibleEtExecutableConserve(): void
    {
        file_put_contents($this->racine . '/photo.webp', 'x');
        chmod($this->racine . '/photo.webp', 0600);
        file_put_contents($this->racine . '/outil', '#!/bin/sh');
        chmod($this->racine . '/outil', 0700);

        copy($this->racine . '/photo.webp', $this->racine . '/copie.webp');
        DroitsFichiers::fichierCopie($this->racine . '/photo.webp', $this->racine . '/copie.webp');
        copy($this->racine . '/outil', $this->racine . '/outil-copie');
        DroitsFichiers::fichierCopie($this->racine . '/outil', $this->racine . '/outil-copie');

        self::assertSame(0644, self::droits($this->racine . '/copie.webp'));
        self::assertSame(0744, self::droits($this->racine . '/outil-copie'), 'le bit exécutable reste');
    }

    public function testCopieDeMiseAJourLisibleEnUmask077(): void
    {
        $source = $this->racine . '/source';
        mkdir($source . '/public/assets/js', 0700, true);
        file_put_contents($source . '/public/assets/js/nouveau.js', 'js');
        chmod($source . '/public/assets/js/nouveau.js', 0600);
        $cible = $this->racine . '/cible';
        mkdir($cible, 0755);

        MiseAJourService::copyFiles($source, $cible);

        self::assertSame(0755, self::droits($cible . '/public/assets/js'));
        self::assertSame(0644, self::droits($cible . '/public/assets/js/nouveau.js'));
    }
}
