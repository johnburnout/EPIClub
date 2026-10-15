<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableControleManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #88] Tests de caractérisation de ControleManager
 * (méthodes de lecture).
 *
 * ⚠️ Caractérisation stricte, iso-comportement :
 *  - findAll joint le prénom/nom du contrôleur (LEFT JOIN utilisateur)
 *    et applique l'ordre 'date_debut DESC' par défaut.
 *  - findId retourne le fetch() BRUT : false quand le contrôle est
 *    absent (et non null — divergence assumée avec les autres
 *    Managers, à ne pas « corriger » sans ticket dédié).
 *  - findActiveByUser : statut 'ouvert' + LIMIT 1, `?: null`.
 */
final class ControleManagerFindTest extends TestCase
{
    public function testFindAllJoinsControleurAndAppliesDefaultOrder(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([
                ['id' => 1, 'prenom' => 'Ada', 'nom' => 'Lovelace'],
            ]);
            return $stmt;
        });

        $rows = (new TestableControleManager($pdo))->findAll();

        self::assertStringContainsString('LEFT JOIN utilisateur u', $captured);
        self::assertStringContainsString('ORDER BY date_debut DESC', $captured);
        self::assertStringNotContainsString('LIMIT', $captured);
        self::assertSame('Ada', $rows[0]['prenom']);
    }

    public function testFindAllAppliesLimitAndOffset(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        (new TestableControleManager($pdo))->findAll('nom ASC', 5, 10);

        self::assertStringContainsString('ORDER BY nom ASC', $captured);
        self::assertStringContainsString('LIMIT 5 OFFSET 10', $captured);
    }

    public function testFindIdReturnsRawFetchWhenNotFound(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        $result = (new TestableControleManager($pdo))->findId(999);

        self::assertStringContainsString('FROM controle WHERE id = :id', $captured);
        self::assertFalse($result, 'Caractérisation : fetch() brut (false), pas null.');
    }

    public function testFindActiveByUserQueriesOpenControle(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$capturedSql, &$capturedParams) {
            $capturedSql = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });
            $stmt->method('fetch')->willReturn(['id' => 7, 'statut' => 'ouvert']);
            return $stmt;
        });

        $result = (new TestableControleManager($pdo))->findActiveByUser(42);

        self::assertStringContainsString("statut = 'ouvert'", $capturedSql);
        self::assertStringContainsString('LIMIT 1', $capturedSql);
        self::assertSame(['user_id' => 42], $capturedParams);
        self::assertSame(7, $result['id']);
    }

    public function testFindActiveByUserReturnsNullWhenNone(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull((new TestableControleManager($pdo))->findActiveByUser(42));
    }
}
