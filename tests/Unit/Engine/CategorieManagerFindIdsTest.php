<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableCategorieManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #72] Tests Unit de CategorieManager::findIds().
 *
 * Symétrique de FournisseurManagerFindIdsTest.
 */
final class CategorieManagerFindIdsTest extends TestCase
{
    public function testFindIdsReturnsEmptyArrayWithoutQueryOnEmptyInput(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');

        $result = (new TestableCategorieManager($pdo))->findIds([]);

        self::assertSame([], $result);
    }

    public function testFindIdsIndexesResultById(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            self::assertStringContainsString('FROM categorie', $sql);
            self::assertStringContainsString('WHERE id IN (?,?)', $sql);
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([
                ['id' => 5, 'libelle' => 'C1'],
                ['id' => 6, 'libelle' => 'C2'],
            ]);
            return $stmt;
        });

        $result = (new TestableCategorieManager($pdo))->findIds([5, 6]);

        self::assertSame([5, 6], array_keys($result));
        self::assertSame('C1', $result[5]['libelle']);
    }

    public function testFindIdsDeduplicatesInputIds(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use (&$captured) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (?array $params = null) use (&$captured) {
                $captured = $params ?? [];
                return true;
            });
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        (new TestableCategorieManager($pdo))->findIds([5, 5, 6, 5]);

        self::assertSame([5, 6], $captured);
    }
}