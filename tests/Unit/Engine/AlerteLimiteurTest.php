<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\AlerteLimiteur;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit 2026-11-08 R4] Une panne du limiteur de tentatives
 * est consignée pour le super-admin (bandeau) au lieu de rester cachée
 * dans les journaux du serveur.
 */
final class AlerteLimiteurTest extends TestCase
{
    private string $dossier;
    private string $journalErreurs;
    /** @var list<string> */
    private array $journal = [];

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/epiclub-alerte-' . bin2hex(random_bytes(4));
        $this->journalErreurs = (string) ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->journalErreurs);
        @unlink($this->dossier . '/limiteur.json');
        @rmdir($this->dossier);
    }

    public function testAucuneAlerteParDefaut(): void
    {
        self::assertNull($this->alerte()->etat());
    }

    public function testPremierePanneCreeLAlerteEtLaJournalise(): void
    {
        $alerte = $this->alerte();
        $alerte->signaler('connexion', new \PDOException("Table 'login_throttle' doesn't exist"));

        $etat = $alerte->etat();
        self::assertNotNull($etat);
        self::assertSame(1, $etat['nombre']);
        self::assertSame(['connexion'], $etat['sources']);
        self::assertSame($etat['debut'], $etat['dernier']);
        self::assertStringContainsString('login_throttle', $etat['message']);
        self::assertCount(1, $this->journal);
        self::assertStringContainsString('connexion', $this->journal[0]);
    }

    public function testPannesSuivantesCompteesSansNouvelleEntreeAuJournal(): void
    {
        $alerte = $this->alerte();
        $alerte->signaler('connexion', new \RuntimeException('a'));
        $alerte->signaler('connexion', new \RuntimeException('b'));
        $alerte->signaler('mot de passe oublié', new \RuntimeException('c'));

        $etat = $alerte->etat();
        self::assertSame(3, $etat['nombre']);
        self::assertSame(['connexion', 'mot de passe oublié'], $etat['sources']);
        self::assertSame('c', $etat['message']);
        self::assertCount(1, $this->journal);
    }

    public function testAcquittementPuisNouvellePanne(): void
    {
        $alerte = $this->alerte();
        $alerte->signaler('connexion', new \RuntimeException('a'));
        $alerte->acquitter();

        self::assertNull($alerte->etat());

        $alerte->signaler('double authentification', new \RuntimeException('b'));
        self::assertSame(1, $alerte->etat()['nombre']);
        self::assertCount(2, $this->journal);
    }

    public function testJournalEnPanneNEmpechePasLAlerte(): void
    {
        $alerte = new AlerteLimiteur($this->dossier . '/limiteur.json', static function (): void {
            throw new \PDOException('base inaccessible');
        });

        $alerte->signaler('connexion', new \RuntimeException('a'));

        self::assertNotNull($alerte->etat());
    }

    public function testFichierImpossibleAEcrireNeLevePasDException(): void
    {
        $fichier = $this->dossier . '/limiteur.json';
        mkdir($this->dossier);
        // Un dossier à la place du fichier : ouverture impossible.
        mkdir($fichier);
        try {
            (new AlerteLimiteur($fichier))->signaler('connexion', new \RuntimeException('a'));
            $this->addToAssertionCount(1);
        } finally {
            rmdir($fichier);
        }
    }

    public function testFichierCorrompuIgnore(): void
    {
        mkdir($this->dossier);
        file_put_contents($this->dossier . '/limiteur.json', '{pas du json');
        $alerte = $this->alerte();

        self::assertNull($alerte->etat());

        $alerte->signaler('connexion', new \RuntimeException('a'));
        self::assertSame(1, $alerte->etat()['nombre']);
    }

    private function alerte(): AlerteLimiteur
    {
        return new AlerteLimiteur($this->dossier . '/limiteur.json', function (string $action): void {
            $this->journal[] = $action;
        });
    }
}
