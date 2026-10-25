<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\ConfigFile;
use Epiclub\Tests\Integration\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;

/**
 * [2026-10-25] Nombre d'équipements (non supprimés) par catégorie,
 * emplacement et fournisseur : liens « équipements » des référentiels.
 */
final class EquipementManagerCompterIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseFixture $fixture;

    protected function setUp(): void
    {
        try {
            $config = include ConfigFile::defaultPath();
            $this->pdo = new \PDO(
                'mysql:host=' . ($config['DB_HOST'] ?? '127.0.0.1') . ';dbname=' . ($config['DB_NAME'] ?? 'epiclub') . ';charset=utf8mb4',
                $config['DB_USER'] ?? 'root',
                $config['DB_PASS'] ?? '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL de test indisponible : ' . $e->getMessage());
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

    public function testComptages(): void
    {
        $acquisition = $this->fixture->createDraftAcquisition('TEST-COMPTER');
        $fournisseur = (int) $this->pdo->query("SELECT fournisseur_id FROM acquisition WHERE id = $acquisition")->fetchColumn();
        $categorie = $this->fixture->createCategorie('TEST-COMPTER');
        $a = $this->fixture->createEquipement($acquisition, $categorie, 'TEST-COMPTER-1');
        $this->fixture->createEquipement($acquisition, $categorie, 'TEST-COMPTER-2');
        $supprime = $this->fixture->createEquipement($acquisition, $categorie, 'TEST-COMPTER-3');
        $this->pdo->prepare('UPDATE club_equipement SET deleted_at = NOW() WHERE id = ?')->execute([$supprime]);
        $emplacement = (int) $this->pdo->query('SELECT id FROM emplacement ORDER BY id LIMIT 1')->fetchColumn();
        if ($emplacement > 0) {
            $this->pdo->prepare('UPDATE club_equipement SET emplacement_id = ? WHERE id = ?')->execute([$emplacement, $a]);
        }

        $manager = new EquipementManager();
        $avant = $manager->compterParFournisseur()[$fournisseur] ?? 0;

        self::assertSame(2, $manager->compterPar('categorie_id')[$categorie] ?? 0);
        if ($emplacement > 0) {
            self::assertGreaterThanOrEqual(1, $manager->compterPar('emplacement_id')[$emplacement] ?? 0);
        }
        self::assertGreaterThanOrEqual(2, $avant);

        $this->expectException(\InvalidArgumentException::class);
        $manager->compterPar('password');
    }
}
