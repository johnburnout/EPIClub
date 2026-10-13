<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableFournisseurManager;
use PHPUnit\Framework\TestCase;

final class FournisseurManagerFindIdsTest extends TestCase
{
    public function testFindIdsReturnsEmptyArrayWithoutQueryOnEmptyInput(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');

        $result = (new TestableFournisseurManager($pdo))->findIds([]);

        self::assertSame([], $result);
    }

    public function testFindIdsIndexesResultById(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            self::assertStringContainsString('WHERE id IN (?,?)', $sql);
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([
                ['id' => 5, 'nom' => 'F1'],
                ['id' => 6, 'nom' => 'F2'],
            ]);
            return $stmt;
        });

        $result = (new TestableFournisseurManager($pdo))->findIds([5, 6]);

        self::assertSame([5, 6], array_keys($result));
        self::assertSame('F1', $result[5]['nom']);
    }

    public function testFindIdsDeduplicatesInputIds(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use (&$captured) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $params) use (&$captured) {
                $captured = $params;
                return true;
            });
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        (new TestableFournisseurManager($pdo))->findIds([5, 5, 6, 5]);

        self::assertSame([5, 6], $captured);
    }
}