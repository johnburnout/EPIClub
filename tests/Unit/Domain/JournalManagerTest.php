<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\JournalManager;
use Epiclub\Tests\Unit\Domain\Support\TestableJournalManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JournalManagerTest extends TestCase
{
    private TestableJournalManager $manager;

    protected function setUp(): void
    {
        $this->manager = new TestableJournalManager($this->createMock(\PDO::class));
        $this->manager->injectDb();
    }

    // ---------------------------------------------------------------
    // Bloc A — getPdo() supprimé
    // ---------------------------------------------------------------

    public function testGetPdoMethodIsRemoved(): void
    {
        self::assertFalse(
            method_exists(JournalManager::class, 'getPdo'),
            'JournalManager::getPdo() doit être supprimé — $this->db existe '
            . 'toujours via AbstractManager.'
        );
    }

    // ---------------------------------------------------------------
    // Bloc B — buildOrderByClause() (logique pure)
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{0:?string, 1:?string, 2:string}>
     */
    public static function orderByProvider(): array
    {
        return [
            // Nominal
            'vide + vide'                => [null,             null,   'c.date_debut DESC'],
            'vide + asc'                 => [null,             'asc',  'c.date_debut ASC'],
            'vide + desc'                => [null,             'desc', 'c.date_debut DESC'],

            // Whitelist contrôlé (direction par défaut DESC)
            'controleur + vide'          => ['controleur',     null,   'u.nom, u.prenom DESC'],
            'controleur + asc'           => ['controleur',     'asc',  'u.nom, u.prenom ASC'],
            'controleur + desc'          => ['controleur',     'desc', 'u.nom, u.prenom DESC'],

            'date_debut + vide'          => ['date_debut',     null,   'c.date_debut DESC'],
            'date_debut + asc'           => ['date_debut',     'asc',  'c.date_debut ASC'],

            'date_fin + vide'            => ['date_fin',       null,   'c.date_fin DESC'],
            'date_fin + desc'            => ['date_fin',       'desc', 'c.date_fin DESC'],

            // Casse de la direction (normalisée)
            'date_debut + ASC'           => ['date_debut',     'ASC',  'c.date_debut ASC'],
            'date_debut + DESC'          => ['date_debut',     'DESC', 'c.date_debut DESC'],

            // Direction invalide → ignorée, direction par défaut DESC
            'date_debut + xyz'           => ['date_debut',     'xyz',  'c.date_debut DESC'],

            // Colonne inconnue → fallback défaut, PAS de concaténation
            // (⚠️ c'est le fix du bug 'c.date_debut DESC ASC')
            'inconnu + vide'             => ['inconnu',        null,   'c.date_debut DESC'],
            'inconnu + asc'              => ['inconnu',        'asc',  'c.date_debut ASC'],
            'inconnu + desc'             => ['inconnu',        'desc', 'c.date_debut DESC'],

            // Injection → fallback défaut, pas d'injection SQL possible
            'injection + vide'           => ['x; DROP TABLE',  null,   'c.date_debut DESC'],
            'injection + asc'            => ['x; DROP TABLE',  'asc',  'c.date_debut ASC'],
            'quote injection'            => ["x' OR '1'='1",   null,   'c.date_debut DESC'],
        ];
    }

    #[DataProvider('orderByProvider')]
    public function testBuildOrderByClause(
        ?string $orderBy,
        ?string $orderDir,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->manager->exposeBuildOrderByClause($orderBy, $orderDir),
        );
    }

    // ---------------------------------------------------------------
    // Bloc C — getControlesClotures() tape sur $this->db
    // ---------------------------------------------------------------

    public function testGetControlesCloturesUsesDbAndReturnsStruct(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('fetch')->willReturnCallback(fn() => ['total' => 0]);
        $dataStmt = $this->createMock(\PDOStatement::class);
        $dataStmt->method('fetchAll')->willReturnCallback(fn() => []);

        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($countStmt, $dataStmt);

        $manager = new TestableJournalManager($pdo);
        $manager->injectDb();

        $result = $manager->getControlesClotures([]);

        self::assertSame([], $result['data']);
        self::assertSame(0, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(10, $result['limit']);
    }

    public function testGetControlesCloturesBuildsOrderByFromWhitelist(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('fetch')->willReturnCallback(fn() => ['total' => 0]);
        $dataStmt = $this->createMock(\PDOStatement::class);
        $dataStmt->method('fetchAll')->willReturnCallback(fn() => []);

        $capturedSql = null;
        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnCallback(
                function (string $sql) use (&$capturedSql, $countStmt, $dataStmt) {
                    if (str_contains($sql, 'ORDER BY')) {
                        $capturedSql = $sql;
                        return $dataStmt;
                    }
                    return $countStmt;
                }
            );

        $manager = new TestableJournalManager($pdo);
        $manager->injectDb();

        $manager->getControlesClotures([
            'order_by'  => 'controleur',
            'order_dir' => 'asc',
        ]);

        self::assertNotNull($capturedSql);
        self::assertStringContainsString(
            'ORDER BY u.nom, u.prenom ASC',
            $capturedSql,
        );
    }

    public function testGetControlesCloturesFallsBackOnUnknownOrderBy(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('fetch')->willReturnCallback(fn() => ['total' => 0]);
        $dataStmt = $this->createMock(\PDOStatement::class);
        $dataStmt->method('fetchAll')->willReturnCallback(fn() => []);

        $capturedSql = null;
        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnCallback(
                function (string $sql) use (&$capturedSql, $countStmt, $dataStmt) {
                    if (str_contains($sql, 'ORDER BY')) {
                        $capturedSql = $sql;
                        return $dataStmt;
                    }
                    return $countStmt;
                }
            );

        $manager = new TestableJournalManager($pdo);
        $manager->injectDb();

        // ⚠️ C'est le bug : avant, on aurait eu 'c.date_debut DESC ASC'
        // (SQL invalide). Après fix : 'c.date_debut ASC', pas de crash.
        $manager->getControlesClotures([
            'order_by'  => 'x; DROP TABLE',
            'order_dir' => 'asc',
        ]);

        self::assertNotNull($capturedSql);
        self::assertStringContainsString('ORDER BY c.date_debut ASC', $capturedSql);
        self::assertStringNotContainsString('DROP TABLE', $capturedSql);
    }
}