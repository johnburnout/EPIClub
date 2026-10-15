<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableClubManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #88] Tests de caractérisation de ClubManager.
 *
 * ClubManager est un manager atypique : pas de findAll/findId, seulement
 * findParameters() (la table club est un singleton id=1) et save().
 * Ces tests figent le contrat existant avant toute évolution.
 */
final class ClubManagerTest extends TestCase
{
    public function testFindParametersQueriesClubSingleton(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['id' => 1, 'nom' => 'Club Test']);
            return $stmt;
        });

        $result = (new TestableClubManager($pdo))->findParameters();

        self::assertSame('SELECT * FROM club WHERE id=1', $captured);
        self::assertSame(['id' => 1, 'nom' => 'Club Test'], $result);
    }

    public function testFindParametersReturnsNullWhenNoRow(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull((new TestableClubManager($pdo))->findParameters());
    }
}
