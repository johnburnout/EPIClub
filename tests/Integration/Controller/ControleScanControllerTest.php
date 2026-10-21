<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * Tests d'intégration du scan QR dans la page (décision produit
 * 2026-10-19) : endpoint AJAX d'ajout au contrôle ouvert.
 *
 * La partie caméra (html5-qrcode) est purement front ; on teste ici le
 * contrat de l'endpoint : ajoute / deja / hors_controle / introuvable.
 */
final class ControleScanControllerTest extends AbstractControllerTestCase
{
    public function testPageScannerAccessible(): void
    {
        $response = $this->client->get('/controles/scanner');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('qr-reader', $response['body']);
        self::assertStringContainsString('html5-qrcode', $response['body']);
    }

    public function testAjouterSansControleOuvertRenvoieVersLaFiche(): void
    {
        $equipementId = $this->creerEquipement();

        $response = $this->client->post('/controles/scanner/ajouter', [
            'equipement_id' => (string) $equipementId,
        ]);

        self::assertSame(200, $response['status']);
        $json = json_decode($response['body'], true);
        self::assertSame('hors_controle', $json['statut']);
        self::assertSame('/equipements/equipement-' . $equipementId, $json['redirect']);
    }

    public function testAjouterAuControleOuvertPuisDoublon(): void
    {
        $adminId = $this->adminId();
        $this->fixture->createControle($adminId, 'TEST-SCAN-CTRL', 'ouvert');
        $equipementId = $this->creerEquipement();

        // Premier scan : ajouté
        $response = $this->client->post('/controles/scanner/ajouter', [
            'equipement_id' => (string) $equipementId,
        ]);
        $json = json_decode($response['body'], true);
        self::assertSame('ajoute', $json['statut']);
        self::assertSame('TEST-SCAN-CTRL', $json['controle']);

        // Le contrôle contient bien l'équipement
        $pdo = $this->createPdo();
        $nb = (int) $pdo->query(
            "SELECT COUNT(*) FROM controle_ligne cl
             JOIN controle c ON c.id = cl.controle_id
             WHERE c.libelle = 'TEST-SCAN-CTRL' AND cl.equipement_id = $equipementId"
        )->fetchColumn();
        self::assertSame(1, $nb);

        // Second scan : doublon signalé, pas de ligne en plus
        $response = $this->client->post('/controles/scanner/ajouter', [
            'equipement_id' => (string) $equipementId,
        ]);
        $json = json_decode($response['body'], true);
        self::assertSame('deja', $json['statut']);

        $nb = (int) $pdo->query(
            "SELECT COUNT(*) FROM controle_ligne cl
             JOIN controle c ON c.id = cl.controle_id
             WHERE c.libelle = 'TEST-SCAN-CTRL' AND cl.equipement_id = $equipementId"
        )->fetchColumn();
        self::assertSame(1, $nb, 'Pas de doublon en base');
    }

    public function testEquipementIntrouvable(): void
    {
        $response = $this->client->post('/controles/scanner/ajouter', [
            'equipement_id' => '99999999',
        ]);

        $json = json_decode($response['body'], true);
        self::assertSame('introuvable', $json['statut']);
    }

    public function testIdInvalideRejete(): void
    {
        $response = $this->client->post('/controles/scanner/ajouter', [
            'equipement_id' => 'abc',
        ]);

        self::assertSame(400, $response['status']);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function creerEquipement(): int
    {
        $acquisitionId = $this->fixture->createValidatedAcquisition(
            'TEST-SCAN-' . bin2hex(random_bytes(3))
        );

        $pdo = $this->createPdo();
        $pdo->prepare("INSERT INTO categorie (libelle, est_epi) VALUES (?, 1)")
            ->execute(['TEST-SCAN-CAT-' . bin2hex(random_bytes(3))]);
        $categorieId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO club_equipement
                (acquisition_id, categorie_id, reference, libelle, code, statut, nombre, nombre_initial, est_epi)
             VALUES (?, ?, ?, 'Équipement scan test', ?, 0, 1, 1, 1)"
        )->execute([
            $acquisitionId,
            $categorieId,
            'TEST-SCAN-REF-' . bin2hex(random_bytes(3)),
            'TEST-SCAN-CODE-' . bin2hex(random_bytes(3)),
        ]);

        return (int) $pdo->lastInsertId();
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
