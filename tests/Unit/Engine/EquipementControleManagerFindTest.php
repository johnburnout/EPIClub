<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableEquipementControleManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #88] Tests de caractérisation de EquipementControleManager
 * (méthodes de lecture).
 */
final class EquipementControleManagerFindTest extends TestCase
{
    public function testFindAllAppliesOrderAndLimit(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([['id' => 1]]);
            return $stmt;
        });

        $rows = (new TestableEquipementControleManager($pdo))->findAll('date DESC', 3, 2);

        self::assertStringContainsString('FROM club_equipement_controle', $captured);
        self::assertStringContainsString('ORDER BY date DESC', $captured);
        self::assertStringContainsString('LIMIT 3 OFFSET 2', $captured);
        self::assertSame([['id' => 1]], $rows);
    }

    public function testFindIdReturnsRowWhenFound(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['id' => 5, 'resultat' => 'ok']);
            return $stmt;
        });

        $result = (new TestableEquipementControleManager($pdo))->findId(5);

        self::assertSame('ok', $result['resultat']);
    }

    public function testFindIdReturnsNullWhenNotFound(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull((new TestableEquipementControleManager($pdo))->findId(999));
    }
}
