<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableControleLigneManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #88] Tests de caractérisation de ControleLigneManager
 * (méthodes de lecture).
 *
 * ⚠️ Caractérisation stricte, iso-comportement :
 *  - findByControle / findByEquipement joignent respectivement
 *    club_equipement (référence/libelle) et controle (statut).
 *  - findByControleAndEquipement : `?: null` quand absente.
 *  - findId retourne le fetch() BRUT : false quand absente (et non
 *    null — divergence assumée avec les autres Managers).
 */
final class ControleLigneManagerFindTest extends TestCase
{
    public function testFindByControleJoinsEquipementReference(): void
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
            $stmt->method('fetchAll')->willReturn([
                ['id' => 1, 'reference' => 'EQ-1', 'libelle' => 'Mousqueton'],
            ]);
            return $stmt;
        });

        $rows = (new TestableControleLigneManager($pdo))->findByControle(5);

        self::assertStringContainsString('JOIN club_equipement ce', $capturedSql);
        self::assertStringContainsString('WHERE cl.controle_id = :controle_id', $capturedSql);
        self::assertSame(['controle_id' => 5], $capturedParams);
        self::assertSame('EQ-1', $rows[0]['reference']);
    }

    public function testFindByEquipementJoinsControleStatut(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([
                ['id' => 2, 'controle_statut' => 'cloture'],
            ]);
            return $stmt;
        });

        $rows = (new TestableControleLigneManager($pdo))->findByEquipement(9);

        self::assertStringContainsString('JOIN controle c', $captured);
        self::assertStringContainsString('c.statut as controle_statut', $captured);
        self::assertSame('cloture', $rows[0]['controle_statut']);
    }

    public function testFindByControleAndEquipementReturnsNullWhenAbsent(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull(
            (new TestableControleLigneManager($pdo))->findByControleAndEquipement(1, 2)
        );
    }

    public function testFindIdReturnsRawFetchWhenNotFound(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        $result = (new TestableControleLigneManager($pdo))->findId(999);

        self::assertFalse($result, 'Caractérisation : fetch() brut (false), pas null.');
    }
}
