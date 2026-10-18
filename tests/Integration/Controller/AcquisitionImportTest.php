<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * Tests d'intégration de l'import Excel/CSV d'acquisitions, de la
 * référence automatique à la validation et du remplacement de facture
 * sur acquisition validée (fonctionnalité « référencement automatique
 * + import/export », décision produit 2026-10-18).
 */
final class AcquisitionImportTest extends AbstractControllerTestCase
{
    /** @var string[] Fichiers temporaires à supprimer en tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    // ==================================================================
    // Import CSV — groupement par facture + auto-validation
    // ==================================================================

    public function testImportCsvCreeAcquisitionsGroupeesEtValidees(): void
    {
        $csv = implode("\n", [
            'reference;designation;categorie;nombre;lot;facture_reference;facture_date;fournisseur',
            ';Corde 10mm;TEST-IMP-CORDES;2;;TEST-IMP-FA;15/01/2026;TEST-Fournisseur',
            'REF-FORCEE-01;Mousqueton;TEST-IMP-MOUSQ;1;;TEST-IMP-FA;15/01/2026;TEST-Fournisseur',
            ';Casque;TEST-IMP-CASQUES;5;oui;TEST-IMP-FB;16/01/2026;TEST-Fournisseur',
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        $response = $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);

        self::assertSame(200, $response['status'], 'Le rapport doit être rendu. Body: '
            . substr(strip_tags($response['body']), 0, 500));
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('2 acquisition(s) créée(s)', $body);

        $pdo = $this->createPdo();

        // --- Les deux acquisitions : validées, placeholder facture ---
        $stmt = $pdo->query(
            "SELECT * FROM acquisition
             WHERE facture_reference IN ('TEST-IMP-FA', 'TEST-IMP-FB')
             ORDER BY facture_reference"
        );
        $acquisitions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $acquisitions);
        foreach ($acquisitions as $acq) {
            self::assertSame('1', (string) $acq['est_validee'], 'Acquisition auto-validée');
            self::assertSame('facture à importer', $acq['facture_document'], 'Placeholder facture');
        }

        [$acqA, $acqB] = $acquisitions;

        // --- Lignes de l'acquisition A (2 lignes) ---
        $lignesA = $this->lignesDe($acqA['id']);
        self::assertCount(2, $lignesA);
        self::assertSame('1', (string) $lignesA[0]['equipements_generes']);
        self::assertSame('1', (string) $lignesA[1]['equipements_generes']);

        // --- Équipements de l'acquisition A : 2 cordes auto + 1 forcé ---
        $equipementsA = $this->equipementsDe($acqA['id']);
        self::assertCount(3, $equipementsA);

        $referencesA = array_column($equipementsA, 'reference');
        self::assertContains('REF-FORCEE-01', $referencesA, 'Référence forcée utilisée telle quelle');

        $autoA = array_filter($referencesA, static fn($r) => $r !== 'REF-FORCEE-01');
        self::assertCount(2, $autoA);
        foreach ($autoA as $ref) {
            // Modèle par défaut YYCATNNN, année de la facture (2026 → 26)
            self::assertMatchesRegularExpression(
                '/^26[A-Z0-9]{2,8}[0-9]{3}$/',
                $ref,
                "Référence auto conforme au modèle (reçu : $ref)"
            );
        }
        self::assertCount(2, array_unique($autoA), 'Références auto distinctes');

        // --- Acquisition B : lot de 5 → 1 seul équipement, nombre=5 ---
        $equipementsB = $this->equipementsDe($acqB['id']);
        self::assertCount(1, $equipementsB, 'Une ligne en lot génère un seul équipement');
        self::assertSame('5', (string) $equipementsB[0]['nombre']);
        self::assertMatchesRegularExpression(
            '/^26[A-Z0-9]{2,8}[0-9]{3}$/',
            $equipementsB[0]['reference']
        );
    }

    public function testImportIgnoreFactureDejaConnue(): void
    {
        // Pré-existant : la facture TEST-IMP-FDUP est déjà en base
        $this->fixture->createValidatedAcquisition('TEST-IMP-FDUP');

        $csv = implode("\n", [
            'reference;designation;categorie;nombre;lot;facture_reference;facture_date;fournisseur',
            ';Corde;TEST-IMP-DUPCAT;1;;TEST-IMP-FDUP;2026-01-15;TEST-Fournisseur',
            ';Casque;TEST-IMP-NEWCAT;1;;TEST-IMP-FNEW;2026-01-15;TEST-Fournisseur',
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        $response = $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);

        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('existe déjà', $body, 'Le groupe en doublon est rapporté');
        self::assertStringContainsString('1 acquisition(s) créée(s)', $body);

        $pdo = $this->createPdo();
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM acquisition WHERE facture_reference = 'TEST-IMP-FDUP'"
        );
        self::assertSame(1, (int) $stmt->fetchColumn(), 'Jamais de fusion / doublon');

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM acquisition WHERE facture_reference = 'TEST-IMP-FNEW'"
        );
        self::assertSame(1, (int) $stmt->fetchColumn(), 'Le nouveau groupe est importé');
    }

    public function testImportSignaleLignesEnErreur(): void
    {
        $csv = implode("\n", [
            'reference;designation;categorie;nombre;lot;facture_reference;facture_date;fournisseur',
            ';Corde OK;TEST-IMP-OK;1;;TEST-IMP-FERR;2026-01-15;TEST-Fournisseur',
            ';;TEST-IMP-KO;1;;TEST-IMP-FERR2;2026-01-15;TEST-Fournisseur',
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        $response = $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);

        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('désignation est obligatoire', $body);
        self::assertStringContainsString('1 acquisition(s) créée(s)', $body, 'La ligne valide est importée');

        // Le groupe entièrement invalide ne doit PAS être créé
        $pdo = $this->createPdo();
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM acquisition WHERE facture_reference = 'TEST-IMP-FERR2'"
        );
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testImportRejetteExtensionInvalide(): void
    {
        $fichier = $this->makeTempFile('not a spreadsheet', 'txt');

        $response = $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);

        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('Format non accepté', $body);
    }

    // ==================================================================
    // Référence automatique à la validation manuelle d'une acquisition
    // ==================================================================

    public function testValidationManuelleGenereReferencesAuto(): void
    {
        $categorieId = $this->fixture->createCategorie('TEST-VAL');
        $acquisitionId = $this->fixture->createDraftAcquisition('TEST-VAL-AUTO', 'factures/test.pdf');
        $this->fixture->createLigne($acquisitionId, 'TEST-VAL-L1', 'Corde test', $categorieId, 2);

        $response = $this->client->post("/admin/acquisitions/valider/$acquisitionId");

        $this->assertRedirectsTo("/admin/acquisitions/acquisition-$acquisitionId", $response);

        $equipements = $this->equipementsDe($acquisitionId);
        self::assertCount(2, $equipements);
        foreach ($equipements as $e) {
            self::assertMatchesRegularExpression(
                '/^\d{2}[A-Z0-9]{2,8}\d{3}$/',
                $e['reference'],
                'Référence auto selon le modèle, pas l\'ancien format « reference produit-N »'
            );
        }
        self::assertNotEquals(
            $equipements[0]['reference'],
            $equipements[1]['reference'],
            'Chaque unité reçoit un n° d\'ordre distinct'
        );
    }

    // ==================================================================
    // Remplacement de la facture sur acquisition validée
    // ==================================================================

    public function testUpdateFactureSurAcquisitionValidee(): void
    {
        $acquisitionId = $this->fixture->createValidatedAcquisition(
            'TEST-UPD-FACT',
            'facture à importer' // placeholder import
        );

        $pdf = $this->makeTempFile(
            "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\n"
            . "trailer<</Root 1 0 R>>\n%%EOF",
            'pdf'
        );

        $response = $this->client->postMultipart(
            "/admin/acquisitions/acquisition_modification-$acquisitionId",
            ['action' => 'update_facture'],
            ['facture_document' => $pdf]
        );

        $this->assertRedirectsTo("/admin/acquisitions/acquisition-$acquisitionId", $response);

        $pdo = $this->createPdo();
        $stmt = $pdo->prepare("SELECT facture_document FROM acquisition WHERE id = ?");
        $stmt->execute([$acquisitionId]);
        $document = $stmt->fetchColumn();

        self::assertIsString($document);
        self::assertStringStartsWith('factures/', $document, 'Le vrai fichier remplace le placeholder');

        // Nettoyage du fichier téléversé (hors préfixe TEST-)
        $uploaded = dirname(__DIR__, 3) . '/_storage/uploads/' . $document;
        if (file_exists($uploaded)) {
            @unlink($uploaded);
        }
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeTempFile(string $contenu, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epitest_') . '.' . $extension;
        file_put_contents($path, $contenu);
        $this->tempFiles[] = $path;
        // tempnam crée le fichier sans extension : à supprimer aussi
        $this->tempFiles[] = substr($path, 0, -strlen('.' . $extension));
        return $path;
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
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }

    /** @return array<int, array<string,mixed>> */
    private function lignesDe(int $acquisitionId): array
    {
        $stmt = $this->createPdo()->prepare(
            "SELECT * FROM acquisition_ligne WHERE acquisition_id = ? ORDER BY id"
        );
        $stmt->execute([$acquisitionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string,mixed>> */
    private function equipementsDe(int $acquisitionId): array
    {
        $stmt = $this->createPdo()->prepare(
            "SELECT * FROM club_equipement WHERE acquisition_id = ? ORDER BY id"
        );
        $stmt->execute([$acquisitionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
