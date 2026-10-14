<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\CategorieManager;
use Epiclub\Tests\Unit\Engine\Support\TestableAcquisitionLigneManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #80 - Dette 10] Tests Unit de AcquisitionLigneManager::findId().
 *
 * Rend vérifiable l'optimisation #79 (2 requêtes → 1 requête) en
 * mockant CategorieManager via la factory categorieManager() extraite
 * en #80.
 */
final class AcquisitionLigneManagerFindIdTest extends TestCase
{
    public function testFindIdReturnsNullWhenLineNotFound(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        $manager = new TestableAcquisitionLigneManager($pdo);
        self::assertNull($manager->findId(999));
    }

    public function testFindIdReturnsLineWithCategoriePopulated(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn([
                'id' => 1,
                'acquisition_id' => 42,
                'categorie_id' => 5,
            ]);
            return $stmt;
        });

        $categorieManager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $categorieManager->expects(self::once())
            ->method('findIds')
            ->with([5])
            ->willReturn([5 => ['id' => 5, 'libelle' => 'C5']]);

        $manager = (new TestableAcquisitionLigneManager($pdo))
            ->withCategorieManager($categorieManager);

        $ligne = $manager->findId(1);

        self::assertIsArray($ligne);
        self::assertSame(5, $ligne['categorie']['id']);
        self::assertSame('C5', $ligne['categorie']['libelle']);
    }

    public function testFindIdReturnsNullCategorieWhenOrphanFk(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn([
                'id' => 1,
                'acquisition_id' => 42,
                'categorie_id' => 999999,
            ]);
            return $stmt;
        });

        $categorieManager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $categorieManager->method('findIds')->willReturn([]);

        $manager = (new TestableAcquisitionLigneManager($pdo))
            ->withCategorieManager($categorieManager);

        $ligne = $manager->findId(1);

        self::assertIsArray($ligne);
        self::assertNull($ligne['categorie']);
    }
}