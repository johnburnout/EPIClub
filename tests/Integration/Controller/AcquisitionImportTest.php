<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * Tests d'intégration de l'import Excel/CSV d'acquisitions v2
 * (analyse → correspondance des colonnes → import), de la référence
 * automatique, de la durée maximum d'utilisation et du remplacement
 * de facture sur acquisition validée (décisions produit 2026-10-18/19).
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
    // Étape 1 — Analyse : correspondance + lignes non conformes
    // ==================================================================

    public function testAnalyseProposeMappingEtSignaleLignesNonConformes(): void
    {
        $csv = implode("\n", [
            'reference;designation;categorie;nombre;date_achat',
            ';Corde;Cordes;2;2019',
            ';;Cordes;1;2019', // désignation vide (cases fusionnées)
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        $response = $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);

        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Correspondance proposée, modifiable
        self::assertStringContainsString('Étape 2', $body);
        self::assertStringContainsString('name="mapping[0]"', $body);

        // Ligne non conforme présentée AVANT l'import
        self::assertStringContainsString('seront ignorées', $body);
        self::assertStringContainsString('Ligne 3', $body);
        self::assertStringContainsString('désignation est obligatoire', $body);

        // Rien n'a été créé à l'étape d'analyse
        $pdo = $this->createPdo();
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM acquisition WHERE facture_reference LIKE 'TEST-%'")->fetchColumn()
        );
    }

    // ==================================================================
    // Étape 2 — Import : règles métier complètes
    // ==================================================================

    public function testImportCompletReglesMetier(): void
    {
        $this->exigerReferencesLibres('IMP-2020-01-01');

        // Catégorie pré-existante identifiable par son CODE (TCI),
        // avec « durée max » cochée : l'équipement doit en hériter.
        $pdo = $this->createPdo();
        $pdo->prepare("INSERT INTO categorie (libelle, code, est_epi, duree_max_utilisation) VALUES (?, 'TCI', 1, 1)")
            ->execute(['TEST-IMP2-CAT-' . bin2hex(random_bytes(3))]);
        $categorieId = (int) $pdo->lastInsertId();

        $csv = implode("\n", [
            'reference;designation;categorie;nombre;date_achat;date_peremption;facture_reference;fournisseur',
            // Lot (nombre>1), année seule pour les 2 dates, catégorie par code TCI
            ';Corde 10mm;TCI;2;2019;2031;TEST-IMP2-FA;TEST-Fournisseur',
            // Référence forcée, péremption vide, même facture/même date
            'REF-FORCEE-02;Mousqueton;TCI;1;2019;;TEST-IMP2-FA;TEST-Fournisseur',
            // Sans facture → groupement par date d'achat (année seule)
            ';Casque;TEST-IMP2-CASQ;1;2020;;;TEST-AutreFourn',
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        // Étape 1 : analyse
        $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);

        // Étape 2 : confirmation avec le mapping explicite
        $response = $this->client->post('/admin/acquisitions/import', [
            'confirmation' => '1',
            'mapping' => [
                0 => 'reference',
                1 => 'designation',
                2 => 'categorie',
                3 => 'nombre',
                4 => 'date_achat',
                5 => 'date_peremption',
                6 => 'facture_reference',
                7 => 'fournisseur',
            ],
        ]);

        self::assertSame(200, $response['status'], 'Rapport attendu. Body: '
            . substr(strip_tags($response['body']), 0, 500));
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('2 acquisition(s) créée(s)', $body);

        // --- Acquisition FACTURE (TEST-IMP2-FA) ---
        $acqA = $this->acquisitionParReference('TEST-IMP2-FA');
        self::assertNotNull($acqA);
        self::assertSame('1', (string) $acqA['est_validee']);
        self::assertSame('facture à importer', $acqA['facture_document']);
        self::assertSame('2019-01-01', $acqA['facture_date'], 'Année seule → 1er janvier');

        $equipementsA = $this->equipementsDe((int) $acqA['id']);
        self::assertCount(2, $equipementsA, 'Une ligne = un équipement (lot compris)');

        // Ligne 1 : lot de 2, catégorie par code, durée max héritée,
        // péremption 31/12, mise en service = date facture
        $lot = $equipementsA[0];
        self::assertSame('2', (string) $lot['nombre'], 'nombre > 1 → lot (un équipement, quantité 2)');
        self::assertSame($categorieId, (int) $lot['categorie_id'], 'Catégorie résolue par son code TCI');
        self::assertSame('1', (string) $lot['duree_max_utilisation'], 'Durée max héritée de la catégorie');
        self::assertSame('2031-12-31', $lot['date_fin_utilisation'], 'Péremption année seule → 31 décembre');
        self::assertSame('2019-01-01', $lot['date_mise_en_service'], 'Mise en service = date facture');
        self::assertMatchesRegularExpression('/^19TCI[0-9]{3}$/', $lot['reference'], 'Référence auto (modèle, année 2019)');
        self::assertStringNotContainsString('LOT', $lot['code'], 'Pas de suffixe LOT dans le code (2026-10-19)');
        self::assertSame('2', (string) $lot['nombre_initial'], 'Nombre initial du lot conservé (plafond)');
        self::assertSame('1', (string) $equipementsA[1]['nombre_initial'], 'Unité : nombre initial = 1');

        // Ligne 2 : référence forcée, péremption vide sur une catégorie
        // à durée de vie limitée → 31/12 de l'année d'achat + 10 ans
        // (décision produit 2026-10-20)
        $mousqueton = $equipementsA[1];
        self::assertSame('REF-FORCEE-02', $mousqueton['reference']);
        self::assertSame('2029-12-31', $mousqueton['date_fin_utilisation']);
        self::assertSame('2019-01-01', $mousqueton['date_mise_en_service']);

        // --- Acquisition SANS FACTURE (groupement par date) ---
        $acqB = $this->acquisitionParReference('IMP-2020-01-01');
        self::assertNotNull($acqB, 'Référence synthétique IMP-AAAA-MM-JJ');
        self::assertSame('1', (string) $acqB['est_validee']);
        self::assertSame('facture à importer', $acqB['facture_document']);

        $equipementsB = $this->equipementsDe((int) $acqB['id']);
        self::assertCount(1, $equipementsB);
        self::assertSame('2020-01-01', $equipementsB[0]['date_mise_en_service']);
        self::assertMatchesRegularExpression('/^20[A-Z0-9]{2,8}[0-9]{3}$/', $equipementsB[0]['reference']);
    }

    public function testImportDateAchatVideDonneDateCourante(): void
    {
        $this->exigerReferencesLibres('IMP-' . date('Y-m-d'));

        $csv = implode("\n", [
            'designation;categorie;nombre;date_achat',
            'Corde;TEST-IMP3-CORDES;1;',
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);
        $response = $this->client->post('/admin/acquisitions/import', [
            'confirmation' => '1',
            'mapping' => [
                0 => 'designation',
                1 => 'categorie',
                2 => 'nombre',
                3 => 'date_achat',
            ],
        ]);

        self::assertSame(200, $response['status']);

        $acq = $this->acquisitionParReference('IMP-' . date('Y-m-d'));
        self::assertNotNull($acq, 'Date vide → date courante, groupe IMP-date du jour');
        self::assertSame(date('Y-m-d'), $acq['facture_date']);
    }

    public function testImportCategorieInconnueRattacheeAUneExistante(): void
    {
        $this->exigerReferencesLibres('IMP-2019-01-01');

        // Catégorie cible pré-existante (décision produit 2026-10-19 :
        // à l'analyse, une catégorie inconnue du fichier peut être
        // rattachée à une existante au lieu d'être créée).
        $pdo = $this->createPdo();
        $pdo->prepare("INSERT INTO categorie (libelle, code, est_epi) VALUES (?, 'T5C', 1)")
            ->execute(['TEST-IMP5-CIBLE-' . bin2hex(random_bytes(3))]);
        $cibleId = (int) $pdo->lastInsertId();

        $csv = implode("\n", [
            'designation;categorie;date_achat',
            'Corde;TEST-IMP5-INCONNUE;2019',
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        // Étape 1 : l'analyse propose le choix de rattachement
        $response = $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);
        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('Catégories du fichier', $body);
        self::assertStringContainsString('Créer la catégorie', $body);
        self::assertStringContainsString('categories[TEST-IMP5-INCONNUE]', $body);

        // Étape 2 : rattachement explicite à la catégorie existante
        $response = $this->client->post('/admin/acquisitions/import', [
            'confirmation' => '1',
            'mapping' => [0 => 'designation', 1 => 'categorie', 2 => 'date_achat'],
            'categories' => ['TEST-IMP5-INCONNUE' => (string) $cibleId],
        ]);

        self::assertSame(200, $response['status'], 'Rapport attendu. Body: '
            . substr(strip_tags($response['body']), 0, 500));

        $acq = $this->acquisitionParReference('IMP-2019-01-01');
        self::assertNotNull($acq);
        $equipements = $this->equipementsDe((int) $acq['id']);
        self::assertCount(1, $equipements);
        self::assertSame($cibleId, (int) $equipements[0]['categorie_id'], 'Rattachée à la catégorie choisie');

        // La catégorie inconnue n'a PAS été créée
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM categorie WHERE libelle = 'TEST-IMP5-INCONNUE'")->fetchColumn()
        );
    }

    public function testImportAvecEnteteDetecteAuDelaDeLaLigne1(): void
    {
        $this->exigerReferencesLibres('IMP-2016-01-01');

        // Fichier réel type : lignes vides + titre avant l'en-tête,
        // en-têtes du fichier d'inventaire du club (décision 2026-10-19).
        $csv = implode("\n", [
            '',
            'Inventaire du matériel',
            '',
            'Catégorie;EPI;ACHAT;HA- Périme;stock théorique;stock réel;Manque;Remarque',
            'TEST-IMP4-CAT;Baudrier enfant;2016;2026;2;;;',
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        // Étape 1 : l'analyse détecte l'en-tête ligne 4 et le signale
        $response = $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);
        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('ligne 4', $body);

        // Étape 2 : import avec le mapping proposé (alias fichier réel)
        $response = $this->client->post('/admin/acquisitions/import', [
            'confirmation' => '1',
            'mapping' => [
                0 => 'categorie',
                1 => 'designation',
                2 => 'date_achat',
                3 => 'date_peremption',
                4 => 'nombre',
            ],
        ]);

        self::assertSame(200, $response['status'], 'Rapport attendu. Body: '
            . substr(strip_tags($response['body']), 0, 500));
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('1 acquisition(s) créée(s)', $body);

        // Acquisition groupée par date (pas de facture) : année seule
        // → 1er janvier ; stock théorique 2 → lot ; péremption 2026
        // → 31 décembre.
        $acq = $this->acquisitionParReference('IMP-2016-01-01');
        self::assertNotNull($acq);
        $equipements = $this->equipementsDe((int) $acq['id']);
        self::assertCount(1, $equipements);
        self::assertSame('2', (string) $equipements[0]['nombre'], 'stock théorique 2 → lot');
        self::assertSame('2026-12-31', $equipements[0]['date_fin_utilisation']);
    }

    public function testImportIgnoreFactureDejaConnue(): void
    {
        $this->fixture->createValidatedAcquisition('TEST-IMP-FDUP');

        $csv = implode("\n", [
            'designation;categorie;nombre;facture_reference;fournisseur',
            'Corde;TEST-IMP-DUPCAT;1;TEST-IMP-FDUP;TEST-Fournisseur',
            'Casque;TEST-IMP-NEWCAT;1;TEST-IMP-FNEW;TEST-Fournisseur',
            '',
        ]);
        $fichier = $this->makeTempFile($csv, 'csv');

        $this->client->postMultipart('/admin/acquisitions/import', [], ['fichier' => $fichier]);
        $response = $this->client->post('/admin/acquisitions/import', [
            'confirmation' => '1',
            'mapping' => [
                0 => 'designation',
                1 => 'categorie',
                2 => 'nombre',
                3 => 'facture_reference',
                4 => 'fournisseur',
            ],
        ]);

        self::assertSame(200, $response['status']);
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('existe déjà', $body, 'Le groupe en doublon est rapporté');
        self::assertStringContainsString('1 acquisition(s) créée(s)', $body);

        $pdo = $this->createPdo();
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM acquisition WHERE facture_reference = 'TEST-IMP-FDUP'")->fetchColumn(),
            'Jamais de fusion / doublon'
        );
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM acquisition WHERE facture_reference = 'TEST-IMP-FNEW'")->fetchColumn()
        );
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

    /**
     * Les imports « par date » créent des acquisitions IMP-AAAA-MM-JJ.
     * Si la base de dev en contient déjà une (vrais imports), l'import la
     * respecterait (pas de fusion) : le test n'a pas de sens, on l'ignore
     * plutôt que de toucher aux données réelles.
     */
    private function exigerReferencesLibres(string ...$references): void
    {
        foreach ($references as $reference) {
            if ($this->fixture->referenceAcquisitionPreexistante($reference)) {
                self::markTestSkipped("La base contient déjà l'acquisition « $reference » (données réelles) : test ignoré.");
            }
        }
    }

    private function acquisitionParReference(string $factureReference): ?array
    {
        $stmt = $this->createPdo()->prepare(
            "SELECT * FROM acquisition WHERE facture_reference = ?"
        );
        $stmt->execute([$factureReference]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
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
