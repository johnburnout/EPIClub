<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\DureeDeVie;
use Epiclub\Tests\Integration\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;

/**
 * Migration 014 (rattrapage de la date limite d'utilisation) : le SQL
 * applique exactement la règle PHP DureeDeVie::finDeVie(), et seulement
 * aux équipements « durée max » sans date, non supprimés.
 *
 * ⚠️ Tout se passe dans une transaction annulée à la fin : les données
 * réelles d'une base de développement ne sont jamais modifiées.
 */
final class Migration014DateLimiteIntegrationTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../migrations/014_date_limite_existants.sql';

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        try {
            $this->pdo = $this->createPdo();
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL de test indisponible : ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testRattrapageSelonLaRegleDureeDeVie(): void
    {
        $fixture = new DatabaseFixture($this->pdo);
        $categorie = $fixture->createCategorie('TEST-M014');

        $cas = [
            // nom => [date de facture, durée max, date limite initiale, supprimé, attendu]
            'mars'              => ['2026-03-15', 1, null, false, DureeDeVie::finDeVie('2026-03-15')],
            '29 février'        => ['2024-02-29', 1, null, false, DureeDeVie::finDeVie('2024-02-29')],
            'janvier'           => ['2019-01-01', 1, null, false, DureeDeVie::finDeVie('2019-01-01')],
            'date déjà saisie'  => ['2026-03-15', 1, '2030-06-30', false, '2030-06-30'],
            'sans durée max'    => ['2026-03-15', 0, null, false, null],
            'supprimé'          => ['2026-03-15', 1, null, true, null],
        ];

        $ids = [];
        foreach ($cas as $nom => [$facture, $dureeMax, $limite, $supprime]) {
            $acquisition = $fixture->createValidatedAcquisition('TEST-M014-' . bin2hex(random_bytes(3)));
            $this->pdo->prepare('UPDATE acquisition SET facture_date = ? WHERE id = ?')->execute([$facture, $acquisition]);
            $equipement = $fixture->createEquipement($acquisition, $categorie, 'TEST-M014-' . bin2hex(random_bytes(3)));
            $this->pdo->prepare(
                'UPDATE club_equipement SET duree_max_utilisation = ?, date_fin_utilisation = ?, deleted_at = ? WHERE id = ?'
            )->execute([$dureeMax, $limite, $supprime ? date('Y-m-d H:i:s') : null, $equipement]);
            $ids[$nom] = $equipement;
        }

        $this->appliquerMigration();
        $this->appliquerMigration(); // idempotente

        foreach ($cas as $nom => $c) {
            $stmt = $this->pdo->prepare('SELECT date_fin_utilisation FROM club_equipement WHERE id = ?');
            $stmt->execute([$ids[$nom]]);
            self::assertSame($c[4], $stmt->fetchColumn() ?: null, $nom);
        }
        self::assertSame('2036-03-31', DureeDeVie::finDeVie('2026-03-15'), 'règle de référence');
    }

    private function appliquerMigration(): void
    {
        $sql = (string) file_get_contents(self::MIGRATION);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $this->pdo->exec(trim($sql));
    }

    private function createPdo(): \PDO
    {
        $envFile = dirname(__DIR__, 3) . '/.env.local.php';
        $config = file_exists($envFile) ? (include $envFile) : [];
        $config = is_array($config) ? $config : [];

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
