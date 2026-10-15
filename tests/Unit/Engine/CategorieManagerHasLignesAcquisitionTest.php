<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableCategorieManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ Dette 9] Tests Unit de
 * CategorieManager::hasLignesAcquisition().
 *
 * Garde applicative du futur FOREIGN KEY acquisition_ligne.categorie_id
 * (RESTRICT) : sans elle, la suppression d'une catégorie référencée
 * par des lignes d'acquisition créait des lignes orphelines
 * (categorie_id pointant vers rien).
 */
final class CategorieManagerHasLignesAcquisitionTest extends TestCase
{
    public function testHasLignesAcquisitionQueriesAcquisitionLigneTable(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn('1');
            return $stmt;
        });

        $result = (new TestableCategorieManager($pdo))->hasLignesAcquisition(7);

        self::assertTrue($result);
        self::assertIsString($captured);
        self::assertStringContainsString('FROM acquisition_ligne', $captured);
        self::assertStringContainsString('categorie_id', $captured);
    }

    public function testHasLignesAcquisitionReturnsFalseWhenCountIsZero(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn('0');
            return $stmt;
        });

        self::assertFalse((new TestableCategorieManager($pdo))->hasLignesAcquisition(7));
    }
}
