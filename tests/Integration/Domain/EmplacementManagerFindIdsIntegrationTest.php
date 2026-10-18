<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\EmplacementManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #112] Test Integration de caractérisation de
 * EmplacementManager::findIds().
 *
 * Objectif : figer la FORME du résultat (map indexée par id, id absent
 * si inconnu — iso avec findId() qui retournait null) sur jeu stable.
 *
 * ⚠️ Pas de serveur HTTP requis. Seul MySQL est nécessaire.
 *
 * L'emplacement de test est inséré directement en PDO (libelle UNIQUE,
 * suffixe aléatoire) et supprimé en tearDown — DatabaseFixture n'a pas
 * de helper emplacement.
 */
final class EmplacementManagerFindIdsIntegrationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = $this->createPdo();
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL de test indisponible : ' . $e->getMessage());
            return;
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->cleanup();
        }
    }

    public function testFindIdsReturnsIdKeyedMapAndSkipsUnknown(): void
    {
        // Cas 1 : emplacement existant.
        $libelle = 'TEST-112-EMPL-' . bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO emplacement (libelle) VALUES (?)')
            ->execute([$libelle]);
        $existant = (int) $this->pdo->lastInsertId();

        // Cas 2 : id inconnu en base.
        $inconnu = 999999;

        $map = (new EmplacementManager())->findIds([$existant, $inconnu]);

        // Cas 1 : présent, clé = id, ligne complète.
        self::assertArrayHasKey($existant, $map);
        self::assertIsArray($map[$existant]);
        self::assertSame($libelle, $map[$existant]['libelle']);

        // Cas 2 : inconnu absent (iso findId → null).
        self::assertArrayNotHasKey($inconnu, $map);

        self::assertCount(1, $map);
    }

    public function testFindIdsEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], (new EmplacementManager())->findIds([]));
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM emplacement WHERE libelle LIKE 'TEST-112-EMPL-%'");
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
