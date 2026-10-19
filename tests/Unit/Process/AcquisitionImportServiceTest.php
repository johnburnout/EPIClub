<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Process;

use Epiclub\Process\AcquisitionImportService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de AcquisitionImportService::analyser().
 *
 * L'analyse ne touche pas la base (pas de manager) : on travaille sur
 * de vrais fichiers CSV temporaires.
 */
final class AcquisitionImportServiceTest extends TestCase
{
    private AcquisitionImportService $service;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->service = new AcquisitionImportService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    public function testAnalyserDevineLaCorrespondanceDesColonnes(): void
    {
        $fichier = $this->csv(implode("\n", [
            'reference;designation;categorie;nombre;date_achat;date_peremption;facture_reference;fournisseur',
            ';Corde;Cordes;2;2019;2031;F-001;Petzl',
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        self::assertNull($analyse['erreur_globale']);
        self::assertSame(1, $analyse['nb_lignes']);

        $champs = array_column($analyse['colonnes'], 'champ', 'entete');
        self::assertSame('reference', $champs['reference']);
        self::assertSame('designation', $champs['designation']);
        self::assertSame('categorie', $champs['categorie']);
        self::assertSame('nombre', $champs['nombre']);
        self::assertSame('date_achat', $champs['date_achat']);
        self::assertSame('date_peremption', $champs['date_peremption']);
        self::assertSame('facture_reference', $champs['facture_reference']);
        self::assertSame('fournisseur', $champs['fournisseur']);
    }

    public function testAnalyserColonneInconnueIgnoree(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;couleur',
            'Corde;Cordes;rouge',
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        $champs = array_column($analyse['colonnes'], 'champ', 'entete');
        self::assertSame('ignorer', $champs['couleur']);
    }

    public function testAnalyserEnTetesAccentsEtCasse(): void
    {
        $fichier = $this->csv(implode("\n", [
            'Désignation;Catégorie;Date achat;Péremption',
            'Corde;Cordes;2019;2031',
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        $champs = array_column($analyse['colonnes'], 'champ', 'entete');
        self::assertSame('designation', $champs['Désignation']);
        self::assertSame('categorie', $champs['Catégorie']);
        self::assertSame('date_achat', $champs['Date achat']);
        self::assertSame('date_peremption', $champs['Péremption']);
    }

    public function testAnalyserSignaleLesLignesNonConformes(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;nombre;date_achat',
            'Corde;Cordes;2;2019',
            ';Cordes;1;2019',          // designation vide (case fusionnée typique)
            'Corde;;1;2019',           // categorie vide
            'Corde;Cordes;abc;2019',   // nombre non numérique
            'Corde;Cordes;1;nimporte', // date invalide
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        self::assertSame(5, $analyse['nb_lignes']);
        self::assertCount(4, $analyse['lignes_non_conformes']);

        $parLigne = array_column($analyse['lignes_non_conformes'], 'raison', 'ligne');
        self::assertStringContainsString('désignation', $parLigne[3]);
        self::assertStringContainsString('catégorie', $parLigne[4]);
        self::assertStringContainsString('nombre', $parLigne[5]);
        self::assertStringContainsString("date d'achat", $parLigne[6]);

        // Le contenu complet de chaque ligne non conforme est fourni
        // pour vérification avant import (décision produit 2026-10-19).
        $donneesParLigne = array_column($analyse['lignes_non_conformes'], 'donnees', 'ligne');
        self::assertSame(['', 'Cordes', '1', '2019'], $donneesParLigne[3]);
        self::assertSame('nimporte', $donneesParLigne[6][3]);
    }

    public function testAnalyserLignesVidesIgnoreesSilencieusement(): void
    {
        $fichier = $this->csv("designation;categorie\n;;\nCorde;Cordes\n;;;;\n");

        $analyse = $this->service->analyser($fichier);

        self::assertSame(1, $analyse['nb_lignes']);
        self::assertSame([], $analyse['lignes_non_conformes']);
    }

    public function testAnalyserFichierVide(): void
    {
        $fichier = $this->csv('');

        $analyse = $this->service->analyser($fichier);

        self::assertNotNull($analyse['erreur_globale']);
    }

    public function testAnalyserFichierIllisible(): void
    {
        // Binaire dans un .xls : le reader dédié échoue à la lecture.
        $path = tempnam(sys_get_temp_dir(), 'epitest_') . '.xls';
        file_put_contents($path, "\x00\x01\x02\x03 not a spreadsheet \xFF\xFE");
        $this->tempFiles[] = $path;
        $this->tempFiles[] = substr($path, 0, -4);

        $analyse = $this->service->analyser($path);

        self::assertNotNull($analyse['erreur_globale']);
    }

    public function testImportRefuseMappingIncomplet(): void
    {
        // Pas besoin de fichier lisible : la validation du mapping
        // précède la lecture.
        $rapport = $this->service->import('/nonexistent.csv', 1, [0 => 'designation']);

        self::assertNotNull($rapport['erreur_globale']);
        self::assertStringContainsString('Catégorie', $rapport['erreur_globale']);
    }

    public function testImportRefuseChampEnDouble(): void
    {
        $rapport = $this->service->import('/nonexistent.csv', 1, [
            0 => 'designation',
            1 => 'categorie',
            2 => 'categorie', // doublon
        ]);

        self::assertNotNull($rapport['erreur_globale']);
        self::assertStringContainsString('plusieurs colonnes', $rapport['erreur_globale']);
    }

    public function testAnalyserCollecteLesCategoriesDistinctes(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;nombre',
            'Corde;Cordes;2',
            'Casque;Casques;1',
            'Autre corde;Cordes;1',
            ';Cordes;1', // non conforme, mais catégorie déjà collectée
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        self::assertSame(['Cordes', 'Casques'], $analyse['categories_fichier']);
    }

    public function testAnalyserDetecteEnteteAuDelaDeLaLigne1(): void
    {
        // Fichier réel type : lignes vides + titre fusionné avant
        // l'en-tête (ligne 4).
        $fichier = $this->csv(implode("\n", [
            '',
            'Inventaire du matériel',
            '',
            'designation;categorie;nombre;date_achat',
            'Corde;Cordes;2;2019',
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        self::assertNull($analyse['erreur_globale']);
        self::assertSame(4, $analyse['ligne_entete']);
        self::assertSame(1, $analyse['nb_lignes']);

        $champs = array_column($analyse['colonnes'], 'champ', 'entete');
        self::assertSame('designation', $champs['designation']);
        self::assertSame('categorie', $champs['categorie']);
    }

    public function testAnalyserSansAliasReconnuPrendLaPremiereLigneNonVide(): void
    {
        $fichier = $this->csv(implode("\n", [
            '',
            'foo;bar',
            'Corde;Cordes',
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        self::assertSame(2, $analyse['ligne_entete']);
        $champs = array_column($analyse['colonnes'], 'champ', 'entete');
        self::assertSame('ignorer', $champs['foo']);
    }

    public function testAnalyserDevineLesAliasDuFichierReel(): void
    {
        // En-têtes du fichier d'inventaire réel du club.
        $fichier = $this->csv(implode("\n", [
            'Catégorie;EPI;ACHAT;HA- Périme;stock théorique;stock réel;Manque;Remarque',
            'Baudriers;Enfant Torse;2016;2026;1;;;',
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        self::assertSame(1, $analyse['ligne_entete']);
        $champs = array_column($analyse['colonnes'], 'champ', 'entete');
        self::assertSame('categorie', $champs['Catégorie']);
        self::assertSame('designation', $champs['EPI']);
        self::assertSame('date_achat', $champs['ACHAT']);
        self::assertSame('date_peremption', $champs['HA- Périme']);
        self::assertSame('nombre', $champs['stock théorique']);
        self::assertSame('ignorer', $champs['stock réel']);
        self::assertSame('ignorer', $champs['Manque']);
        self::assertSame('ignorer', $champs['Remarque']);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function csv(string $contenu): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epitest_') . '.csv';
        file_put_contents($path, $contenu);
        $this->tempFiles[] = $path;
        $this->tempFiles[] = substr($path, 0, -4);
        return $path;
    }
}
