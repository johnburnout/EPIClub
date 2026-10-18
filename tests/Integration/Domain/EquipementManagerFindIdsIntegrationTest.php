<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\EquipementManager;
use Epiclub\Tests\Integration\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #112] Test Integration de caractérisation de
 * EquipementManager::findIds().
 *
 * Objectif : figer la FORME du résultat (map indexée par id, id absent
 * si inconnu ou soft-deleted — iso avec findId() qui retournait null)
 * sur jeu stable.
 *
 * ⚠️ Pas de serveur HTTP requis. Seul MySQL est nécessaire.
 *
 * Prérequis : categorie_id = 1 existe (hypothèse déjà posée par les
 * autres tests Integration).
 */
final class EquipementManagerFindIdsIntegrationTest extends TestCase
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

    public function testFindIdsReturnsIdKeyedMapAndSkipsUnknownAndSoftDeleted(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-112-FINDIDS');

        // Cas 1 : équipement actif.
        $actif = $this->fixture->createEquipement($acqId, 1, 'TEST-112-A', 'Équipement actif');

        // Cas 2 : équipement soft-deleted — findId() retournait null,
        // findIds() doit l'exclure de la map.
        $supprime = $this->fixture->createEquipement($acqId, 1, 'TEST-112-B', 'Équipement supprimé');
        $this->pdo->prepare('UPDATE club_equipement SET deleted_at = NOW() WHERE id = ?')
            ->execute([$supprime]);

        // Cas 3 : id inconnu en base.
        $inconnu = 999999;

        $map = (new EquipementManager())->findIds([$actif, $supprime, $inconnu]);

        // Cas 1 : présent, clé = id, ligne complète.
        self::assertArrayHasKey($actif, $map);
        self::assertIsArray($map[$actif]);
        self::assertSame('TEST-112-A', $map[$actif]['reference']);

        // Cas 2 : soft-deleted absent (iso findId → null).
        self::assertArrayNotHasKey($supprime, $map);

        // Cas 3 : inconnu absent (iso findId → null).
        self::assertArrayNotHasKey($inconnu, $map);

        self::assertCount(1, $map);
    }

    public function testFindIdsEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], (new EquipementManager())->findIds([]));
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
