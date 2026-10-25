<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\VerrouInstance;
use Epiclub\Exception\VerrouOccupeException;
use PHPUnit\Framework\TestCase;

/**
 * Verrou commun d'une instance (var/epiclub.lock).
 * Deux flock() sur deux ouvertures distinctes du même fichier s'excluent,
 * y compris dans un même processus : le test simule ainsi deux opérations.
 */
final class VerrouInstanceTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/epiclub-verrou-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->dossier . '/var/epiclub.lock');
        @rmdir($this->dossier . '/var');
        @rmdir($this->dossier);
    }

    public function testPourRacineViseVarEpiclubLock(): void
    {
        self::assertSame($this->dossier . '/var/epiclub.lock', VerrouInstance::pourRacine($this->dossier . '/')->fichier());
    }

    public function testAcquerirCreeLeDossierEtNoteLOperation(): void
    {
        $verrou = VerrouInstance::pourRacine($this->dossier);
        $verrou->acquerir('installation');

        self::assertTrue($verrou->estAcquis());
        self::assertStringStartsWith('installation pid=' . getmypid(), (string) file_get_contents($verrou->fichier()));
        $verrou->liberer();
        self::assertFalse($verrou->estAcquis());
    }

    public function testUneSecondeOperationEstRefuseeAvecLeNomDeLaPremiere(): void
    {
        $premiere = VerrouInstance::pourRacine($this->dossier);
        $premiere->acquerir('mise-a-jour');
        try {
            $this->expectException(VerrouOccupeException::class);
            $this->expectExceptionMessage('une mise à jour est déjà en cours');
            VerrouInstance::pourRacine($this->dossier)->acquerir('sauvegarde');
        } finally {
            $premiere->liberer();
        }
    }

    public function testLeVerrouLibereEstReutilisable(): void
    {
        $premiere = VerrouInstance::pourRacine($this->dossier);
        $premiere->acquerir('sauvegarde');
        $premiere->liberer();

        $seconde = VerrouInstance::pourRacine($this->dossier);
        $seconde->acquerir('restauration');
        self::assertTrue($seconde->estAcquis());
        $seconde->liberer();
    }

    public function testExecuterLibereMemeEnCasDException(): void
    {
        $verrou = VerrouInstance::pourRacine($this->dossier);
        try {
            $verrou->executer('sauvegarde', static function (): void {
                throw new \RuntimeException('échec');
            });
            self::fail('Exception attendue');
        } catch (\RuntimeException $e) {
            self::assertSame('échec', $e->getMessage());
        }
        self::assertFalse($verrou->estAcquis());
        self::assertSame(42, $verrou->executer('sauvegarde', static fn () => 42));
    }

    public function testDoubleAcquisitionParLeMemeObjetEstUneErreurDeProgrammation(): void
    {
        $verrou = VerrouInstance::pourRacine($this->dossier);
        $verrou->acquerir('sauvegarde');
        try {
            $this->expectException(\LogicException::class);
            $verrou->acquerir('sauvegarde');
        } finally {
            $verrou->liberer();
        }
    }
}
