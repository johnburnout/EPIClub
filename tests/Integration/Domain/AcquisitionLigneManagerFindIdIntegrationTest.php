<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Tests\Integration\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #79 - Dette 11] Test de caractérisation pour
 * AcquisitionLigneManager::findId().
 *
 * Avant #79 : 2 requêtes (SELECT acquisition_ligne + CategorieManager::findId()).
 * Après #79 : 1 requête (SELECT acquisition_ligne + CategorieManager::findIds([$id])).
 *
 * ⚠️ Le comptage de requêtes n'est pas réalisable en Unit/Integration
 *    depuis ce test : `new CategorieManager()` dans findId() ouvre sa
 *    propre connexion PDO (AbstractManager::__construct), donc un
 *    CountingPdo injecté dans AcquisitionLigneManager ne verrait que
 *    la 1ère requête. Le comptage fiable nécessite Dette 10
 *    (injection des managers dans les Managers).
 *
 * Ce test valide donc l'ISO-COMPORTEMENT strict avant/après #79.
 */
final class AcquisitionLigneManagerFindIdIntegrationTest extends TestCase
{
    private ?\PDO $pdo = null;
    private ?DatabaseFixture $fixture = null;

    protected function setUp(): void
    {
        try {
            $this->pdo = new \PDO(
                'mysql:host=localhost;dbname=epiclub;charset=utf8mb4',
                'root',
                '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL indisponible : ' . $e->getMessage());
        }

        $this->fixture = new DatabaseFixture($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->cleanupTestData();
        }
        $this->pdo = null;
        $this->fixture = null;
    }

    public function testFindIdReturnsLineWithCategorieWhenFound(): void
    {
        $categorieId = $this->fixture->createCategorie('TEST-79-CAT');
        $acquisitionId = $this->fixture->createDraftAcquisition('TEST-79-ACQ');
        $ligneId = $this->fixture->createLigne(
            $acquisitionId,
            'TEST-79-LIGNE',
            'Ligne test #79',
            $categorieId
        );

        $manager = new AcquisitionLigneManager();
        $ligne = $manager->findId($ligneId);

        self::assertIsArray($ligne);
        self::assertSame($ligneId, (int) $ligne['id']);
        self::assertIsArray($ligne['categorie']);
        self::assertSame($categorieId, (int) $ligne['categorie']['id']);
    }

    public function testFindIdReturnsNullWhenLineNotFound(): void
    {
        $manager = new AcquisitionLigneManager();
        self::assertNull($manager->findId(999999999));
    }

    public function testFindIdReturnsNullCategorieWhenOrphanFk(): void
    {
        $acquisitionId = $this->fixture->createDraftAcquisition('TEST-79-ORPHAN-ACQ');
        $orphanCategorieId = 999999999;
        $ligneId = $this->fixture->createLigne(
            $acquisitionId,
            'TEST-79-ORPHAN-LIGNE',
            'Ligne orpheline #79',
            $orphanCategorieId
        );

        $manager = new AcquisitionLigneManager();
        $ligne = $manager->findId($ligneId);

        self::assertIsArray($ligne);
        self::assertNull($ligne['categorie']);
    }
}