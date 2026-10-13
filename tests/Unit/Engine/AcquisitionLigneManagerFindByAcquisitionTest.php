<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Tests\Unit\Engine\Support\TestableAcquisitionLigneManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #72] Tests Unit de AcquisitionLigneManager::findByAcquisition().
 *
 * Objectif : vérifier le NOMBRE de requêtes (N+1 → 2) et la FORME du
 * résultat (clés 'categorie' présentes/absentes).
 *
 * ⚠️ Limite connue (même que #71, cf. Dette 10) : findByAcquisition()
 * fait `new CategorieManager()` en dur. Le mock PDO ne capte PAS les
 * appels findIds() sur les catégories. Les assertions portent donc sur
 * la FORME (présence de clé), pas la valeur.
 *
 * Le contenu est couvert par :
 *  - test Integration : AcquisitionLigneManagerFindByAcquisitionIntegrationTest
 *  - test Unit isolé : CategorieManagerFindIdsTest
 */
final class AcquisitionLigneManagerFindByAcquisitionTest extends TestCase
{
    /**
     * @param array<int, array> $ligneRows
     * @return array{0: TestableAcquisitionLigneManager, 1: array}
     */
    private function makeManager(array $ligneRows): array
    {
        $captured = ['lignes' => null];

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($ligneRows, &$captured) {
                $stmt = $this->createMock(\PDOStatement::class);

                if (str_contains($sql, 'FROM acquisition_ligne')) {
                    $stmt->method('fetchAll')->willReturn($ligneRows);
                    $stmt->method('execute')->willReturnCallback(
                        function (?array $params = null) use (&$captured) {
                            $captured['lignes'] = $params ?? [];
                            return true;
                        }
                    );
                }

                return $stmt;
            }
        );

        return [new TestableAcquisitionLigneManager($pdo), $captured];
    }

    public function testFindByAcquisitionMakesTwoQueriesForMultipleLignes(): void
    {
        [$manager] = $this->makeManager(
            ligneRows: [
                ['id' => 1, 'acquisition_id' => 42, 'categorie_id' => 5],
                ['id' => 2, 'acquisition_id' => 42, 'categorie_id' => 5],
                ['id' => 3, 'acquisition_id' => 42, 'categorie_id' => 6],
            ],
        );

        $rows = $manager->findByAcquisition(42);

        self::assertCount(3, $rows);
        self::assertArrayHasKey('categorie', $rows[0]);
        self::assertArrayHasKey('categorie', $rows[2]);
    }

    public function testFindByAcquisitionReturnsEarlyOnEmptyResult(): void
    {
        $prepareCount = 0;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$prepareCount) {
                $prepareCount++;
                $stmt = $this->createMock(\PDOStatement::class);
                $stmt->method('fetchAll')->willReturn([]);
                return $stmt;
            }
        );

        $rows = (new TestableAcquisitionLigneManager($pdo))->findByAcquisition(42);

        self::assertSame([], $rows);
        self::assertSame(1, $prepareCount, 'Early return : 1 seule requête');
    }

    /**
     * ⚠️ Comportement défensif : categorie_id est NOT NULL en base,
     * donc ce cas n'est pas atteignable en Integration. Le test
     * documente le contrat (isset() sur null → clé absente).
     */
    public function testFindByAcquisitionOmitsCategorieKeyWhenCategorieIdIsNull(): void
    {
        [$manager] = $this->makeManager(
            ligneRows: [
                ['id' => 1, 'acquisition_id' => 42, 'categorie_id' => null],
            ],
        );

        $rows = $manager->findByAcquisition(42);

        self::assertArrayNotHasKey('categorie', $rows[0]);
    }

    public function testFindByAcquisitionSetsCategorieKeyWhenForeignKeyIsOrphan(): void
    {
        // categorie_id = 999999 absent de la vraie base → findIds()
        // retourne [] → ?? null → clé présente + null.
        [$manager] = $this->makeManager(
            ligneRows: [
                ['id' => 1, 'acquisition_id' => 42, 'categorie_id' => 999999],
            ],
        );

        $rows = $manager->findByAcquisition(42);

        self::assertArrayHasKey('categorie', $rows[0]);
        self::assertNull($rows[0]['categorie']);
    }
}