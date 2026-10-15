<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Tests\Integration\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #71] Test Integration de caractérisation de
 * AcquisitionManager::findAll().
 *
 * Objectif : figer la FORME du résultat (clés présentes/absentes,
 * valeurs null) sur un jeu stable, AVANT et APRÈS le refactor
 * 2N+1 → 3 requêtes.
 *
 * ⚠️ Ce test teste une méthode Domain, pas un contrôleur.
 * Il n'hérite PAS de AbstractControllerTestCase : pas besoin de
 * serveur HTTP ni de login admin. Seul MySQL de test est requis.
 *
 * ⚠️ Le schéma déclare acquisition.fournisseur_id NOT NULL :
 *    le cas « fournisseur_id = NULL » n'est donc pas testable en
 *    Integration. Il reste couvert en Unit (comportement défensif).
 *
 * Prérequis base : fournisseur id=1 et utilisateur id=1 existent
 * (hypothèse déjà posée par DatabaseFixture::createDraftAcquisition()).
 */
final class AcquisitionManagerFindAllIntegrationTest extends TestCase
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

    public function testFindAllPreservesHistoricalShapeOnStableDataset(): void
    {
        // Cas 1 : fournisseur + rédacteur non-null.
        $idWithBoth = $this->fixture->createAcquisitionForFindAll(
            reference: 'TEST-71-WITH-BOTH',
            fournisseurId: 1,
            saisiePar: 1,
        );

        // Cas 2 : FK orpheline → fournisseur_id pointe vers un id absent.
        // ⚠️ La colonne est NOT NULL → on ne peut PAS tester le cas null.
        // Le isset() de findAll() reste défensif (couvert en Unit).
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $idOrphan = $this->fixture->createAcquisitionForFindAll(
                reference: 'TEST-71-ORPHAN',
                fournisseurId: 999999,
                saisiePar: 1,
            );
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $rows = (new AcquisitionManager())->findAll('', -1, 0);
        $byId = array_column($rows, null, 'id');

        self::assertArrayHasKey($idWithBoth, $byId);
        self::assertArrayHasKey($idOrphan, $byId);

        // Cas 1 : clés 'fournisseur' ET 'redacteur' présentes.
        self::assertArrayHasKey('fournisseur', $byId[$idWithBoth]);
        self::assertArrayHasKey('redacteur',   $byId[$idWithBoth]);

        // Cas 2 : clé 'fournisseur' PRÉSENTE + null (FK orpheline).
        // ?? null dans findAll() reproduit le findId() → null historique.
        self::assertArrayHasKey('fournisseur', $byId[$idOrphan]);
        self::assertNull($byId[$idOrphan]['fournisseur']);
        self::assertArrayHasKey('redacteur', $byId[$idOrphan]);
    }

    /**
     * [SÉCURITÉ Dette 8] 'redacteur' est exposé dans les vues acquisition :
     * il ne doit contenir AUCUNE colonne sensible (password, reset_token…).
     * Avant la projection publique, findIds() faisait un SELECT * →
     * ce test est ROUGE (redacteur.password présent).
     */
    public function testFindAllDoesNotExposeSensitiveColumnsInRedacteur(): void
    {
        $id = $this->fixture->createAcquisitionForFindAll(
            reference: 'TEST-DETTE8',
            fournisseurId: 1,
            saisiePar: 1,
        );

        $rows = (new AcquisitionManager())->findAll('', -1, 0);
        $byId = array_column($rows, null, 'id');

        self::assertArrayHasKey($id, $byId);
        self::assertArrayHasKey('redacteur', $byId[$id]);

        $redacteur = $byId[$id]['redacteur'];
        self::assertIsArray($redacteur);

        // Champs publics toujours présents (utilisés par les vues)
        self::assertArrayHasKey('nom', $redacteur);
        self::assertArrayHasKey('prenom', $redacteur);

        // Colonnes sensibles strictement absentes
        foreach (['password', 'reset_token', 'reset_token_expires', 'reset_email_sent_at'] as $column) {
            self::assertArrayNotHasKey(
                $column,
                $redacteur,
                "Colonne sensible '$column' exposée dans redacteur (Dette 8)."
            );
        }
    }

    /**
     * Aligné sur AbstractControllerTestCase::createPdo()
     * (même mécanisme de lecture .env.local.php, mêmes options PDO).
     */
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