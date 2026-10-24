<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\TicketManager;
use Epiclub\Engine\ConfigFile;
use Epiclub\Tests\Integration\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;

/**
 * [2026-10-24] TicketManager (migration 018) sur MySQL : création avec
 * modifications, lectures jointes (équipement, demandeur, traitant),
 * tickets traitables par rôle, clôture unique, notification du demandeur.
 *
 * Données préfixées TEST- et supprimées en fin de test.
 */
final class TicketManagerIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseFixture $fixture;
    private int $utilisateur;

    protected function setUp(): void
    {
        try {
            $this->pdo = $this->createPdo();
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL de test indisponible : ' . $e->getMessage());
        }
        if ($this->pdo->query("SHOW TABLES LIKE 'ticket'")->fetchColumn() === false) {
            self::markTestSkipped('Migration 018 (tickets) non appliquée.');
        }
        $utilisateur = $this->pdo->query('SELECT id FROM utilisateur ORDER BY id LIMIT 1')->fetchColumn();
        if ($utilisateur === false) {
            self::markTestSkipped('Aucun utilisateur en base.');
        }
        $this->utilisateur = (int) $utilisateur;
        $this->fixture = new DatabaseFixture($this->pdo);
        $this->fixture->cleanupTestData();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanupTestData();
        }
    }

    public function testCycleDUnTicket(): void
    {
        $acquisition = $this->fixture->createDraftAcquisition('TEST-TICKET');
        $categorie = $this->fixture->createCategorie('TEST-TICKET');
        $equipement = $this->fixture->createEquipement($acquisition, $categorie, 'TEST-TICKET-1', 'Corde de test');
        $manager = new TicketManager();

        $admin = $manager->creer($equipement, $this->utilisateur, 'ROLE_ADMIN',
            ['dissociation' => 'En 2 + 1', 'statut' => 'En maintenance'], '2026-10-24 10:00:00');
        $superAdmin = $manager->creer($equipement, $this->utilisateur, 'ROLE_SUPER_ADMIN',
            ['categorie' => 'Longe'], '2026-10-24 10:00:01');

        $ticket = $manager->findId($admin);
        self::assertSame('ouvert', $ticket['statut']);
        self::assertSame('TEST-TICKET-1', $ticket['equipement_reference']);
        self::assertNotEmpty($ticket['demandeur_nom']);
        self::assertSame([
            ['type' => 'dissociation', 'remarque' => 'En 2 + 1'],
            ['type' => 'statut', 'remarque' => 'En maintenance'],
        ], $ticket['modifications']);

        $ids = fn (array $tickets) => array_values(array_intersect(
            array_map(static fn ($t) => (int) $t['id'], $tickets), [$admin, $superAdmin]
        ));
        self::assertSame([$admin], $ids($manager->findOuvertsPourRoles(['ROLE_ADMIN'])));
        self::assertSame([$admin, $superAdmin], $ids($manager->findOuvertsPourRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])));
        self::assertSame([$superAdmin, $admin], $ids($manager->findParDemandeur($this->utilisateur)));

        // Clôture unique : un second traitement est refusé.
        self::assertTrue($manager->cloturer($admin, 'refuse', $this->utilisateur, 'Déjà fait', '2026-10-24 11:00:00'));
        self::assertFalse($manager->cloturer($admin, 'fait', $this->utilisateur, null, '2026-10-24 11:05:00'));
        $clos = $manager->findId($admin);
        self::assertSame(['refuse', 'Déjà fait', '2026-10-24 11:00:00'], [$clos['statut'], $clos['reponse'], $clos['date_traitement']]);
        self::assertNotEmpty($clos['traite_par_nom']);

        // Le ticket clos quitte les listes « à traiter » et notifie le demandeur
        // jusqu'à ce qu'il le consulte.
        self::assertSame([$superAdmin], $ids($manager->findOuvertsPourRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])));
        self::assertSame([$admin], $ids($manager->findClosNonVus($this->utilisateur)));
        $manager->marquerVu($admin, $this->utilisateur);
        self::assertSame([], $ids($manager->findClosNonVus($this->utilisateur)));
    }

    private function createPdo(): \PDO
    {
        $config = include ConfigFile::defaultPath();
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
