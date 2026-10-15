<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\AbstractManager;
use PHPUnit\Framework\TestCase;

/**
 * Sous-classe testable : injecte un PDO mocké, déclare tableName()
 * et expose baseWhereClause() pour le hook de filtre permanent.
 */
final class TestableFindOneManager extends AbstractManager
{
    public function __construct(\PDO $pdo)
    {
        // Ne PAS appeler parent::__construct() : il ouvrirait MySQL.
        $this->db = $pdo;
    }

    protected function tableName(): string
    {
        return 'testable_table';
    }
}

/**
 * Sous-classe avec filtre permanent (cas EquipementManager : soft
 * delete via deleted_at IS NULL).
 */
final class TestableSoftDeleteManager extends AbstractManager
{
    public function __construct(\PDO $pdo)
    {
        $this->db = $pdo;
    }

    protected function tableName(): string
    {
        return 'testable_table';
    }

    protected function baseWhereClause(): string
    {
        return ' WHERE deleted_at IS NULL';
    }
}

/**
 * Sous-classe SANS tableName() : doit lever une RuntimeException claire.
 */
final class TestableNoTableManager extends AbstractManager
{
    public function __construct(\PDO $pdo)
    {
        $this->db = $pdo;
    }
}

/**
 * [REFACTOR #87] Tests de caractérisation de
 * AbstractManager::findOneByCriteria() générique.
 *
 * Objectif : figer le contrat commun avant de supprimer les 5 copies
 * dupliquées dans les Managers (Utilisateur, Fournisseur, Emplacement,
 * Categorie, EquipementControle) et de faire passer EquipementManager
 * (soft delete) sur le hook baseWhereClause().
 *
 * Contrat caractérisé :
 *  - SELECT * FROM <tableName> + WHERE critères d'égalité (buildWhereClause)
 *  - critères vides → pas de WHERE
 *  - fetch() false → null (et non false)
 *  - nom de colonne invalide → InvalidArgumentException (buildWhereClause)
 *  - tableName() non défini → RuntimeException explicite
 *  - baseWhereClause() préfixe le WHERE des critères (soft delete)
 */
final class AbstractManagerFindOneByCriteriaTest extends TestCase
{
    public function testFindOneByCriteriaBuildsSelectWithWhereClause(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['id' => 7, 'email' => 'a@b.c']);
            return $stmt;
        });

        $result = (new TestableFindOneManager($pdo))->findOneByCriteria(['email' => 'a@b.c']);

        self::assertSame('SELECT * FROM testable_table WHERE email=:email', $captured);
        self::assertSame(['id' => 7, 'email' => 'a@b.c'], $result);
    }

    public function testFindOneByCriteriaWithoutCriteriaOmitsWhere(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['id' => 1]);
            return $stmt;
        });

        $result = (new TestableFindOneManager($pdo))->findOneByCriteria();

        self::assertSame('SELECT * FROM testable_table', $captured);
        self::assertSame(['id' => 1], $result);
    }

    public function testFindOneByCriteriaReturnsNullWhenNotFound(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull(
            (new TestableFindOneManager($pdo))->findOneByCriteria(['id' => 999])
        );
    }

    public function testFindOneByCriteriaRejectsInvalidColumnName(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');

        $this->expectException(\InvalidArgumentException::class);

        (new TestableFindOneManager($pdo))->findOneByCriteria(['email OR 1=1 --' => 'x']);
    }

    public function testFindOneByCriteriaThrowsWhenTableNameNotDefined(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tableName()');

        (new TestableNoTableManager($pdo))->findOneByCriteria(['id' => 1]);
    }

    public function testBaseWhereClausePrefixesCriteriaWhere(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['id' => 1]);
            return $stmt;
        });

        (new TestableSoftDeleteManager($pdo))->findOneByCriteria(['reference' => 'R1']);

        // Cas EquipementManager : 'WHERE deleted_at IS NULL AND ref=:ref'
        self::assertSame(
            'SELECT * FROM testable_table WHERE deleted_at IS NULL AND reference=:reference',
            $captured
        );
    }

    public function testBaseWhereClauseAloneWhenCriteriaEmpty(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        (new TestableSoftDeleteManager($pdo))->findOneByCriteria();

        self::assertSame('SELECT * FROM testable_table WHERE deleted_at IS NULL', $captured);
    }
}
