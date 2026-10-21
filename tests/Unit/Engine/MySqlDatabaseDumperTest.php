<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\MySqlDatabaseDumper;
use Epiclub\Tests\Unit\Engine\Support\FakeMysqlPdo;
use PHPUnit\Framework\TestCase;

/**
 * Format du fichier produit par MySqlDatabaseDumper (sans serveur MySQL).
 * L'aller-retour réel est couvert par MySqlDatabaseDumperIntegrationTest.
 */
final class MySqlDatabaseDumperTest extends TestCase
{
    private FakeMysqlPdo $pdo;
    private string $fichier;

    protected function setUp(): void
    {
        $this->pdo = new FakeMysqlPdo();
        $this->fichier = sys_get_temp_dir() . '/epiclub-dump-unit-' . bin2hex(random_bytes(4)) . '.sql';

        $this->pdo->exec("CREATE TABLE `schema_migrations` (\n  version VARCHAR(64) PRIMARY KEY\n)");
        $this->pdo->exec("INSERT INTO schema_migrations VALUES ('002_b'), ('001_a')");
        $this->pdo->exec("CREATE TABLE `club_equipement` (\n  id INTEGER PRIMARY KEY,\n  libelle TEXT,\n  photo BLOB,\n  calcule TEXT\n)");
        $ins = $this->pdo->prepare('INSERT INTO club_equipement (id, libelle, photo, calcule) VALUES (?, ?, ?, ?)');
        $ins->execute([1, "Baudrier\nligne 2 ; -- l'été \\", "\x00\x01\n", 'x']);
        $ins->execute([2, null, null, 'x']);
        $this->pdo->extras = ['club_equipement' => ['calcule' => 'VIRTUAL GENERATED']];
    }

    protected function tearDown(): void
    {
        @unlink($this->fichier);
    }

    public function testExportUneInstructionParLigne(): void
    {
        $comptes = (new MySqlDatabaseDumper($this->pdo))->exporter($this->fichier);

        self::assertSame(['club_equipement' => 2, 'schema_migrations' => 2], $comptes);

        $lignes = file($this->fichier, FILE_IGNORE_NEW_LINES);
        self::assertSame(MySqlDatabaseDumper::ENTETE, $lignes[0]);
        self::assertContains('SET FOREIGN_KEY_CHECKS = 0;', $lignes);

        $instructions = array_values(array_filter($lignes, fn ($l) => $l !== '' && !str_starts_with($l, '--')));
        foreach ($instructions as $l) {
            self::assertStringEndsWith(';', $l);
        }

        $creation = array_values(array_filter($lignes, fn ($l) => str_starts_with($l, 'CREATE TABLE `club_equipement`')));
        self::assertCount(1, $creation, 'CREATE TABLE sur une seule ligne');

        $insert = array_values(array_filter($lignes, fn ($l) => str_starts_with($l, 'INSERT INTO `club_equipement`')));
        self::assertSame(
            "INSERT INTO `club_equipement` (`id`, `libelle`, `photo`) VALUES "
            . "(1, 'Baudrier\\nligne 2 ; -- l\\'été \\\\', 0x00010a), (2, NULL, NULL);",
            $insert[0],
            'colonne générée exclue, binaire en hexadécimal, NULL, échappement MySQL'
        );
    }

    /**
     * Régression : MySQL ≥ 8.0.13 affiche Extra = DEFAULT_GENERATED pour une
     * colonne DEFAULT CURRENT_TIMESTAMP (ex. pret.created_at). Elle doit être
     * exportée ; seules les colonnes VIRTUAL/STORED GENERATED sont exclues.
     */
    public function testColonneAvecDefautGenereEstExportee(): void
    {
        $this->pdo->exec("CREATE TABLE `pret` (\n  id INTEGER PRIMARY KEY,\n  created_at TEXT,\n  maj TEXT,\n  total TEXT\n)");
        $this->pdo->exec("INSERT INTO pret VALUES (1, '2026-01-02 03:04:05', 'm', 't')");
        $this->pdo->extras['pret'] = [
            'created_at' => 'DEFAULT_GENERATED',
            'maj'        => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP',
            'total'      => 'STORED GENERATED',
        ];

        (new MySqlDatabaseDumper($this->pdo))->exporter($this->fichier);

        $insert = array_values(array_filter(
            file($this->fichier, FILE_IGNORE_NEW_LINES),
            fn ($l) => str_starts_with($l, 'INSERT INTO `pret`')
        ));
        self::assertSame(
            "INSERT INTO `pret` (`id`, `created_at`, `maj`) VALUES (1, '2026-01-02 03:04:05', 'm');",
            $insert[0] ?? null
        );
    }

    public function testMigrationsAppliquees(): void
    {
        self::assertSame(['001_a', '002_b'], (new MySqlDatabaseDumper($this->pdo))->migrationsAppliquees());
    }

    public function testImportVideLaBasePuisRejoueLesInstructions(): void
    {
        file_put_contents($this->fichier, implode("\n", [
            MySqlDatabaseDumper::ENTETE,
            '-- commentaire',
            '',
            'SET FOREIGN_KEY_CHECKS = 0;',
            'CREATE TABLE `t` (id INT);',
            "INSERT INTO `t` (`id`) VALUES (1), (2);",
        ]) . "\n");

        (new MySqlDatabaseDumper($this->pdo))->importer($this->fichier);

        self::assertSame(['t'], $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
        self::assertNotContains('-- commentaire', $this->pdo->executees);
        self::assertSame('SET FOREIGN_KEY_CHECKS = 1', end($this->pdo->executees));
    }

    public function testImportRefuseUnFichierEtrangerSansRienSupprimer(): void
    {
        file_put_contents($this->fichier, "DROP TABLE club_equipement;\n");
        $avant = count($this->pdo->executees);

        try {
            (new MySqlDatabaseDumper($this->pdo))->importer($this->fichier);
            self::fail('fichier accepté');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('pas un export EPIClub', $e->getMessage());
        }

        $nouvelles = array_slice($this->pdo->executees, $avant);
        self::assertSame([], array_filter($nouvelles, fn ($s) => str_starts_with($s, 'DROP')));
    }

    public function testErreurSqlIndiqueLaLigne(): void
    {
        file_put_contents($this->fichier, MySqlDatabaseDumper::ENTETE . "\nCREATE TABLE `t` (id INT);\nINSERT INTO `absente` VALUES (1);\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ligne 3');
        (new MySqlDatabaseDumper($this->pdo))->importer($this->fichier);
    }
}
