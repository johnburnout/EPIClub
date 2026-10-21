<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\DatabaseBackup;
use Epiclub\Engine\DatabaseDumperInterface;
use Epiclub\Tests\Unit\Engine\Support\FakeDatabaseDumper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [v1.4.0] Sauvegarde SQL automatique avant migrations.
 */
final class DatabaseBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/epiclub_dbbackup_' . bin2hex(random_bytes(4)) . '/backups';
    }

    protected function tearDown(): void
    {
        $racine = dirname($this->dir);
        if (!is_dir($racine)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($racine);
    }

    public function testDumpCreeLeDossierEtUnFichierHorodate(): void
    {
        $base = new FakeDatabaseDumper();
        $base->tables = ['utilisateur' => [['id' => 1]]];

        $fichier = (new DatabaseBackup($base, $this->dir))->dump();

        self::assertMatchesRegularExpression('#/db_\d{4}-\d{2}-\d{2}_\d{6}\.sql$#', $fichier);
        self::assertStringContainsString('utilisateur', (string) file_get_contents($fichier));
        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->dir . '/.htaccess'));
        self::assertSame([], glob($this->dir . '/*.part'));
    }

    public function testDeuxDumpsDansLaMemeSecondeNeSEcrasentPas(): void
    {
        $backup = new DatabaseBackup(new FakeDatabaseDumper(), $this->dir);
        self::assertNotSame($backup->dump(), $backup->dump());
    }

    public function testEchecDuDumpExceptionEtAucunFichier(): void
    {
        $enPanne = new class implements DatabaseDumperInterface {
            public function exporter(string $fichierSql): array
            {
                file_put_contents($fichierSql, 'début');
                throw new \RuntimeException('connexion perdue');
            }

            public function importer(string $fichierSql): void
            {
            }

            public function migrationsAppliquees(): array
            {
                return [];
            }
        };

        try {
            (new DatabaseBackup($enPanne, $this->dir))->dump();
            self::fail('échec non signalé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('connexion perdue', $e->getMessage());
        }
        self::assertSame([], glob($this->dir . '/db_*'));
    }

    public function testCleanupGardeLesNPlusRecentsSansToucherAuReste(): void
    {
        mkdir($this->dir, 0777, true);
        $anciens = [
            'db_2026-01-01_000000.sql', 'db_2026-02-01_000000.sql', 'db_2026-03-01_000000.sql',
            'db_2026-03-01_000000-2.sql', 'db_2026-04-01_120000.sql',
        ];
        foreach ($anciens as $f) {
            touch($this->dir . '/' . $f);
        }
        touch($this->dir . '/epiclub-sauvegarde-20260101-000000.zip');
        touch($this->dir . '/notes.sql');

        (new DatabaseBackup(new FakeDatabaseDumper(), $this->dir))->cleanup(2);

        $restants = array_values(array_diff(scandir($this->dir), ['.', '..']));
        sort($restants);
        self::assertSame([
            'db_2026-03-01_000000-2.sql',
            'db_2026-04-01_120000.sql',
            'epiclub-sauvegarde-20260101-000000.zip',
            'notes.sql',
        ], $restants);
    }

    /** @return array<string, array{mixed, int}> */
    public static function retentions(): array
    {
        return [
            'absente'    => [null, 5],
            'entier'     => [10, 10],
            'chaîne'     => ['3', 3],
            'zéro'       => [0, 5],
            'négative'   => [-2, 5],
            'invalide'   => ['beaucoup', 5],
        ];
    }

    #[DataProvider('retentions')]
    public function testRetention(mixed $valeur, int $attendu): void
    {
        self::assertSame($attendu, DatabaseBackup::retention($valeur));
    }
}
