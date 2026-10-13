<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Tests\Integration\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #72] Test Integration de caractérisation de
 * AcquisitionLigneManager::findByAcquisition().
 *
 * Objectif : figer la FORME du résultat (clé 'categorie' présente
 * ou absente, valeur null sur FK orpheline) sur jeu stable.
 *
 * ⚠️ Pas de serveur HTTP requis. Seul MySQL est nécessaire.
 *
 * Prérequis : acquisition_id existe, categorie_id = 1 existe,
 * utilisateur id=1 existe (hypothèses déjà posées par les autres
 * tests Integration).
 */
final class AcquisitionLigneManagerFindByAcquisitionIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseFixture $fixture;

    protected function setUp(): void
    {
        try {
            $this->pdo = $this->createPdo();
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL de test indisponible : ' . $e->getMessage());
            return;
        }

        $this->fixture = new DatabaseFixture($this->pdo);
        $this->fixture->cleanupTestData();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanupTestData();
        }
    }

    public function testFindByAcquisitionPreservesHistoricalShape(): void
    {
        // Créer une acquisition support (fournisseur_id=1, saisie_par=1).
        $acqId = $this->fixture->createDraftAcquisition('TEST-72-FIND');

        // Cas 1 : ligne avec catégorie existante.
        $ligne1 = $this->fixture->createLigne($acqId, 'TEST-72-A', 'Ligne A', 1);

        // Cas 2 : ligne avec catégorie orpheline.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $ligne2 = $this->fixture->createLigne($acqId, 'TEST-72-B', 'Ligne B', 999999);
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $rows = (new AcquisitionLigneManager())->findByAcquisition($acqId);
        $byId = array_column($rows, null, 'id');

        self::assertArrayHasKey($ligne1, $byId);
        self::assertArrayHasKey($ligne2, $byId);

        // Cas 1 : clé 'categorie' présente + array.
        self::assertArrayHasKey('categorie', $byId[$ligne1]);
        self::assertIsArray($byId[$ligne1]['categorie']);

        // Cas 2 : clé 'categorie' présente + null (FK orpheline).
        self::assertArrayHasKey('categorie', $byId[$ligne2]);
        self::assertNull($byId[$ligne2]['categorie']);
    }

    private function createPdo(): \PDO
    {
        $envFile = dirname(__DIR__, 3) . '/.env.local.php';
        $config = file_exists($envFile) ? (include $envFile) : [];
        if (!is_array($config)) {
            $config = [];
        }

        return new \PDO(
            'mysql:host=' . ($config['DB_HOST'] ?? '127.0.0.1')
            . ';dbname=' . ($config['DB_NAME'] ?? 'epiclub')
            . ';charset=utf8mb4',
            $config['DB_USER'] ?? 'root',
            $config['DB_PASS'] ?? '',
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }
}