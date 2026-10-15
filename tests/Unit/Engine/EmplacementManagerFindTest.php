<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableEmplacementManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #88] Tests de caractérisation de EmplacementManager
 * (méthodes de lecture).
 */
final class EmplacementManagerFindTest extends TestCase
{
    public function testFindAllAppliesOrderAndLimit(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([['id' => 1, 'nom' => 'Salle A']]);
            return $stmt;
        });

        $rows = (new TestableEmplacementManager($pdo))->findAll('nom ASC', 5, 0);

        self::assertStringContainsString('FROM emplacement', $captured);
        self::assertStringContainsString('ORDER BY nom ASC', $captured);
        self::assertStringContainsString('LIMIT 5 OFFSET 0', $captured);
        self::assertSame('Salle A', $rows[0]['nom']);
    }

    public function testFindIdReturnsNullWhenNotFound(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull((new TestableEmplacementManager($pdo))->findId(999));
        self::assertStringContainsString('FROM emplacement WHERE id=:id', $captured);
    }

    public function testHasEquipementsQueriesClubEquipement(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn('3');
            return $stmt;
        });

        self::assertTrue((new TestableEmplacementManager($pdo))->hasEquipements(7));
        self::assertStringContainsString('FROM club_equipement', $captured);
        self::assertStringContainsString('emplacement_id', $captured);
    }
}
