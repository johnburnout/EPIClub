<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Cli;

use Epiclub\Engine\Cli\Commande;
use Epiclub\Engine\Cli\UsageException;
use PHPUnit\Framework\TestCase;

/**
 * Socle des commandes bin/installer.php et bin/sauvegarder.php :
 * arguments, gardes d'exécution, sorties texte et JSON, journal.
 */
final class CommandeTest extends TestCase
{
    /** @var resource */
    private $sortie;
    /** @var resource */
    private $erreurs;
    private string $journal;

    protected function setUp(): void
    {
        $this->sortie = fopen('php://memory', 'w+');
        $this->erreurs = fopen('php://memory', 'w+');
        $this->journal = sys_get_temp_dir() . '/epiclub-cli-' . bin2hex(random_bytes(4)) . '/epiclub-cli.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->journal);
        @rmdir(dirname($this->journal));
    }

    /** @param list<string> $argv */
    private function commande(array $argv): Commande
    {
        return new Commande('essai', $argv, ['nom', 'garder'], ['sec'], $this->journal, $this->sortie, $this->erreurs);
    }

    /** @param resource $flux */
    private function lire($flux): string
    {
        rewind($flux);
        return (string) stream_get_contents($flux);
    }

    public function testOptionsEtDrapeaux(): void
    {
        $cmd = $this->commande(['--nom=Club X=1', '--sec', '--quiet']);
        self::assertSame('Club X=1', $cmd->option('nom'));
        self::assertSame('5', $cmd->option('garder', '5'));
        self::assertTrue($cmd->drapeau('sec'));
        self::assertFalse($cmd->drapeau('json'));
    }

    /** @return array<string, array{list<string>}> */
    public static function argumentsInvalides(): array
    {
        return [
            'option inconnue'           => [['--inconnue=1']],
            'drapeau avec valeur'       => [['--sec=oui']],
            'option valeur sans valeur' => [['--nom']],
            'argument positionnel'      => [['fichier.zip']],
        ];
    }

    /** @param list<string> $argv */
    #[\PHPUnit\Framework\Attributes\DataProvider('argumentsInvalides')]
    public function testArgumentsInvalides(array $argv): void
    {
        $this->expectException(UsageException::class);
        $this->commande($argv);
    }

    public function testEchecUsageEnJson(): void
    {
        self::assertSame(2, Commande::echecUsage('essai', ['--json', '--x'], 'Option inconnue', $this->sortie, $this->erreurs));
        $json = json_decode($this->lire($this->sortie), true);
        self::assertSame(['commande' => 'essai', 'succes' => false, 'code' => 2, 'message' => 'Option inconnue', 'details' => [], 'etapes' => []], $json);
        self::assertSame('', $this->lire($this->erreurs));
    }

    public function testVerifierContexte(): void
    {
        self::assertNull(Commande::verifierContexte('cli', 1000, false));
        self::assertNull(Commande::verifierContexte('cli', null, false));
        self::assertNull(Commande::verifierContexte('cli', 0, true));
        self::assertStringContainsString('ligne de commande', (string) Commande::verifierContexte('fpm-fcgi', 1000, false));
        self::assertStringContainsString('Refus de s\'exécuter en root', (string) Commande::verifierContexte('cli', 0, false));
    }

    public function testSortieTexte(): void
    {
        $cmd = $this->commande([]);
        $cmd->etape('Étape 1');
        self::assertSame(0, $cmd->terminer(Commande::SUCCES, 'Fini.'));
        self::assertSame("Étape 1\nFini.\n", $this->lire($this->sortie));

        self::assertSame(7, $this->commande([])->terminer(7, 'Raté.', [], ['détail']));
        self::assertSame("Erreur : Raté.\n  - détail\n", $this->lire($this->erreurs));
    }

    public function testQuietNAfficheQueLesErreurs(): void
    {
        $cmd = $this->commande(['--quiet']);
        $cmd->etape('Étape');
        $cmd->terminer(Commande::SUCCES, 'Fini.');
        self::assertSame('', $this->lire($this->sortie));

        $this->commande(['--quiet'])->terminer(Commande::VERROU_OCCUPE, 'Occupé.');
        self::assertSame("Erreur : Occupé.\n", $this->lire($this->erreurs));
    }

    public function testSortieJson(): void
    {
        $cmd = $this->commande(['--json']);
        $cmd->etape('Étape');
        self::assertSame(8, $cmd->terminer(Commande::INTERROMPUE, 'Interrompue.', ['fichier' => '/x']));

        $json = json_decode($this->lire($this->sortie), true);
        self::assertSame(false, $json['succes']);
        self::assertSame(8, $json['code']);
        self::assertSame(['Étape'], $json['etapes']);
        self::assertSame('/x', $json['fichier']);
        self::assertSame('', $this->lire($this->erreurs));
    }

    public function testJournalHorodateEn0640(): void
    {
        $cmd = $this->commande(['--quiet']);
        $cmd->etape("Ligne\navec retour");
        $cmd->terminer(Commande::SUCCES, 'Fini.');

        $lignes = file($this->journal, FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lignes);
        self::assertMatchesRegularExpression('/^\S+ \[essai\] INFO pid=\d+ : Ligne avec retour$/', $lignes[0]);
        self::assertStringEndsWith('OK pid=' . getmypid() . ' : Fini. (code 0)', $lignes[1]);
        self::assertSame('0640', substr(sprintf('%o', fileperms($this->journal)), -4));
    }
}
