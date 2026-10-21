<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Engine\MySqlDatabaseDumper;
use PHPUnit\Framework\TestCase;

/**
 * Export / import réels sur MySQL / MariaDB.
 *
 * ⚠️ La base de développement n'est JAMAIS modifiée : elle n'est que
 * lue (export). Les imports se font dans une base jetable
 * `<DB_NAME>_test_sauvegarde`, créée puis supprimée par le test
 * (droit CREATE DATABASE requis — sinon le test est ignoré).
 */
final class MySqlDatabaseDumperIntegrationTest extends TestCase
{
    private ?\PDO $serveur = null;
    private string $baseDev = 'epiclub';
    private string $baseJetable = '';
    private string $fichier = '';

    protected function setUp(): void
    {
        $config = $this->config();
        $this->baseDev = (string) ($config['DB_NAME'] ?? 'epiclub');
        $this->baseJetable = $this->baseDev . '_test_sauvegarde';
        $this->fichier = sys_get_temp_dir() . '/epiclub-dump-' . bin2hex(random_bytes(4)) . '.sql';

        try {
            $this->serveur = $this->pdo(null);
            $this->serveur->exec('DROP DATABASE IF EXISTS `' . $this->baseJetable . '`');
            $this->serveur->exec('CREATE DATABASE `' . $this->baseJetable . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL de test indisponible ou droits insuffisants : ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->serveur !== null) {
            $this->serveur->exec('DROP DATABASE IF EXISTS `' . $this->baseJetable . '`');
        }
        @unlink($this->fichier);
    }

    public function testAllerRetourDonneesDifficiles(): void
    {
        $pdo = $this->pdo($this->baseJetable);
        $pdo->exec('CREATE TABLE parent (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE enfant (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NULL,
            texte TEXT NULL,
            brut BLOB NULL,
            prix DECIMAL(10,2) NULL,
            le_jour DATE NULL,
            double_prix DECIMAL(10,2) AS (prix * 2) VIRTUAL,
            CONSTRAINT fk_enfant_parent FOREIGN KEY (parent_id) REFERENCES parent (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        // id 0 conservé grâce à NO_AUTO_VALUE_ON_ZERO
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
        $pdo->exec("INSERT INTO parent (id, nom) VALUES (0, 'zéro'), (5, 'Club « Été » ✓')");

        $texteDifficile = "ligne 1\nligne 2\r\n-- pas un commentaire;\n'quote' \"double\" \\ antislash ; fin\0nul";
        $binaire = random_bytes(64) . "\n\r\0'";
        $ins = $pdo->prepare('INSERT INTO enfant (parent_id, texte, brut, prix, le_jour) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([5, $texteDifficile, $binaire, '12.50', '2026-10-21']);
        $ins->execute([null, null, null, null, null]);
        for ($i = 0; $i < 3000; $i++) { // plusieurs lots INSERT
            $ins->execute([0, str_repeat('x', 300) . $i, null, $i, null]);
        }

        $dumper = new MySqlDatabaseDumper($pdo);
        $comptes = $dumper->exporter($this->fichier);
        self::assertSame(['enfant' => 3002, 'parent' => 2], $comptes);

        // On abîme la base, puis on restaure.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DELETE FROM enfant');
        $pdo->exec('DROP TABLE parent');
        $pdo->exec('CREATE TABLE intruse (id INT)');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $dumper->importer($this->fichier);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        sort($tables);
        self::assertSame(['enfant', 'parent'], $tables, 'table intruse supprimée');

        self::assertSame(
            [['id' => 0, 'nom' => 'zéro'], ['id' => 5, 'nom' => 'Club « Été » ✓']],
            $pdo->query('SELECT id, nom FROM parent ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC)
        );
        $l = $pdo->query('SELECT * FROM enfant WHERE parent_id = 5')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($texteDifficile, $l['texte']);
        self::assertSame($binaire, $l['brut']);
        self::assertEquals('25.00', $l['double_prix']);
        self::assertSame('2026-10-21', $l['le_jour']);
        self::assertSame(3002, (int) $pdo->query('SELECT COUNT(*) FROM enfant')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM enfant WHERE parent_id IS NULL AND texte IS NULL')->fetchColumn());

        // La clé étrangère est toujours active.
        $this->expectException(\PDOException::class);
        $pdo->exec('INSERT INTO enfant (parent_id) VALUES (999)');
    }

    public function testExportDeLaBaseDeDevRestaurableAIdentique(): void
    {
        try {
            $dev = $this->pdo($this->baseDev);
        } catch (\PDOException $e) {
            self::markTestSkipped('Base de développement indisponible : ' . $e->getMessage());
        }

        $comptes = (new MySqlDatabaseDumper($dev))->exporter($this->fichier);
        self::assertNotEmpty($comptes);

        $jetable = $this->pdo($this->baseJetable);
        $dumperJetable = new MySqlDatabaseDumper($jetable);
        $dumperJetable->importer($this->fichier);

        foreach ($comptes as $table => $n) {
            self::assertSame(
                $n,
                (int) $jetable->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(),
                'table ' . $table
            );
            self::assertSame(
                $this->somme($dev, $table),
                $this->somme($jetable, $table),
                'contenu de ' . $table
            );
        }
        self::assertSame((new MySqlDatabaseDumper($dev))->migrationsAppliquees(), $dumperJetable->migrationsAppliquees());
    }

    public function testImporterRefuseUnFichierQuiNestPasUnExport(): void
    {
        file_put_contents($this->fichier, "DROP TABLE x;\n");
        $pdo = $this->pdo($this->baseJetable);
        $pdo->exec('CREATE TABLE temoin (id INT)');

        try {
            (new MySqlDatabaseDumper($pdo))->importer($this->fichier);
            self::fail('fichier accepté');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('pas un export EPIClub', $e->getMessage());
        }
        self::assertSame(['temoin'], $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN), 'rien supprimé');
    }

    // ------------------------------------------------------------------

    /** Somme de contrôle du contenu d'une table (ordre des lignes neutralisé). */
    private function somme(\PDO $pdo, string $table): string
    {
        $lignes = array_map(
            static fn (array $l) => json_encode(array_map(
                static fn ($v) => $v === null ? null : bin2hex((string) $v),
                $l
            )),
            $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC)
        );
        sort($lignes);
        return hash('sha256', implode("\n", $lignes));
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $envFile = dirname(__DIR__, 3) . '/.env.local.php';
        $config = file_exists($envFile) ? (include $envFile) : [];
        return is_array($config) ? $config : [];
    }

    private function pdo(?string $base): \PDO
    {
        $config = $this->config();
        $dsn = 'mysql:host=' . ($config['DB_HOST'] ?? '127.0.0.1') . ';charset=utf8mb4';
        if ($base !== null) {
            $dsn .= ';dbname=' . $base;
        }
        return new \PDO($dsn, $config['DB_USER'] ?? 'root', $config['DB_PASS'] ?? '', [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
