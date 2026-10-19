<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * Tests d'intégration de l'édition du nombre d'éléments d'un LOT dans
 * la fiche équipement (décision produit 2026-10-19) :
 *  - le nombre initial du lot est conservé (nombre_initial) ;
 *  - le nombre actuel est modifiable entre 1 et nombre_initial, sans
 *    pouvoir dépasser ce plafond ;
 *  - le code de l'équipement ne contient plus le suffixe « LOT ».
 */
final class EquipementControllerEditLotTest extends AbstractControllerTestCase
{
    public function testListeAfficheLeNombrePourUnLot(): void
    {
        $id = $this->creerLot(3);

        $response = $this->client->get('/equipements');

        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('Lot : 3/3', $body);

        // Et le détail du lot confirme restants / initiaux
        $response = $this->client->get('/equipements/equipement-' . $id);
        self::assertSame(200, $response['status']);
        self::assertStringContainsString(
            'restant',
            html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
    }

    public function testFormulaireAfficheNombreInitialEtNombreActuel(): void
    {
        $id = $this->creerLot(3);

        $response = $this->client->get('/equipements/equipement_modification-' . $id);

        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('Nombre initial du lot', $body);
        self::assertStringContainsString('name="nombre"', $body);
        self::assertStringContainsString('max="3"', $body);
    }

    public function testNombreDepassantLeNombreInitialEstRefuse(): void
    {
        $id = $this->creerLot(3);

        $response = $this->client->post('/equipements/equipement_modification-' . $id, [
            'statut' => '0',
            'nombre' => '5', // > nombre_initial (3)
        ]);

        self::assertSame(200, $response['status'], 'Le formulaire est réaffiché avec l\'erreur');
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('nombre initial', $body);

        self::assertSame(3, $this->nombreEnBase($id), 'Le nombre en base est inchangé');
    }

    public function testNombreDansLaPlageEstAccepte(): void
    {
        $id = $this->creerLot(3);

        $response = $this->client->post('/equipements/equipement_modification-' . $id, [
            'statut' => '0',
            'nombre' => '1',
        ]);

        self::assertSame(302, $response['status'], 'Redirection après enregistrement');

        $pdo = $this->createPdo();
        $row = $pdo->query("SELECT nombre, nombre_initial FROM club_equipement WHERE id = $id")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('1', (string) $row['nombre'], 'Nombre actuel mis à jour');
        self::assertSame('3', (string) $row['nombre_initial'], 'Nombre initial non modifiable');
    }

    public function testNombreZeroEstRefuse(): void
    {
        $id = $this->creerLot(3);

        $this->client->post('/equipements/equipement_modification-' . $id, [
            'statut' => '0',
            'nombre' => '0',
        ]);

        self::assertSame(3, $this->nombreEnBase($id), 'Un nombre < 1 est refusé');
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Crée un équipement LOT de test (nombre = nombre_initial).
     */
    private function creerLot(int $nombre): int
    {
        $acquisitionId = $this->fixture->createValidatedAcquisition(
            'TEST-LOT-' . bin2hex(random_bytes(3))
        );

        $pdo = $this->createPdo();
        $pdo->prepare("INSERT INTO categorie (libelle, est_epi) VALUES (?, 1)")
            ->execute(['TEST-LOT-CAT-' . bin2hex(random_bytes(3))]);
        $categorieId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO club_equipement
                (acquisition_id, categorie_id, reference, libelle, code,
                 statut, nombre, nombre_initial, est_epi)
             VALUES (?, ?, ?, 'Lot de test', ?, 0, ?, ?, 1)"
        )->execute([
            $acquisitionId,
            $categorieId,
            'TEST-LOT-REF-' . bin2hex(random_bytes(3)),
            'TEST-LOT-CODE-' . bin2hex(random_bytes(3)),
            $nombre,
            $nombre,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function nombreEnBase(int $equipementId): int
    {
        return (int) $this->createPdo()
            ->query("SELECT nombre FROM club_equipement WHERE id = $equipementId")
            ->fetchColumn();
    }

    /**
     * Copie locale (même code que AcquisitionImportTest) : createPdo()
     * de la classe de base est privée.
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
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }
}
