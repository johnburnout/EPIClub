<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Process;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Process\AcquisitionImportService;
use Epiclub\Process\AcquisitionProcess;
use PHPUnit\Framework\TestCase;

/**
 * Import : sélection des lignes, nom/code des catégories à créer,
 * regroupement et affectation par ligne (décision produit 2026-10-20).
 *
 * Managers remplacés par des doublures en mémoire (pattern Vague 11).
 */
final class AcquisitionImportSelectionTest extends TestCase
{
    /** @var array<int, array> Catégories « en base » indexées par id */
    private array $categories = [];

    /** @var array<int, array> Lignes d'acquisition enregistrées */
    private array $lignesSauvees = [];

    /** @var string[] */
    private array $tempFiles = [];

    private AcquisitionImportService $service;

    protected function setUp(): void
    {
        $this->categories = [
            1 => ['id' => 1, 'libelle' => 'Cordes', 'code' => 'COR'],
            2 => ['id' => 2, 'libelle' => 'Casques', 'code' => 'CAS'],
            // Durée de vie limitée (2026-10-20)
            3 => ['id' => 3, 'libelle' => 'Harnais', 'code' => 'HAR', 'duree_max_utilisation' => 1],
        ];
        $this->lignesSauvees = [];

        $categorieManager = $this->createMock(CategorieManager::class);
        $categorieManager->method('findOneByCriteria')->willReturnCallback(function (array $c) {
            foreach ($this->categories as $cat) {
                $ok = true;
                foreach ($c as $k => $v) {
                    if ((string) ($cat[$k] ?? '') !== (string) $v) {
                        $ok = false;
                    }
                }
                if ($ok) {
                    return $cat;
                }
            }
            return false;
        });
        $categorieManager->method('findAll')->willReturnCallback(fn() => array_values($this->categories));
        $categorieManager->method('save')->willReturnCallback(function (array $cat) {
            $id = max(array_keys($this->categories)) + 1;
            $this->categories[$id] = $cat + ['id' => $id, 'code' => null];
            return (string) $id;
        });

        $ligneManager = $this->createMock(AcquisitionLigneManager::class);
        $ligneManager->method('save')->willReturnCallback(function (array $l) {
            $this->lignesSauvees[] = $l;
            return '1';
        });

        $acquisitionManager = $this->createMock(AcquisitionManager::class);
        $acquisitionManager->method('findOneByCriteria')->willReturn(false);

        $process = $this->createMock(AcquisitionProcess::class);
        $process->method('acquisition_process')->willReturn(10);
        $process->method('validerAcquisition')->willReturn(true);

        $equipementManager = $this->createMock(EquipementManager::class);

        $this->service = new class (
            $categorieManager,
            $ligneManager,
            $acquisitionManager,
            $process,
            $equipementManager
        ) extends AcquisitionImportService {
            public function __construct(
                private $cm,
                private $lm,
                private $am,
                private $p,
                private $em
            ) {
            }
            protected function categorieManager(): CategorieManager { return $this->cm; }
            protected function acquisitionLigneManager(): AcquisitionLigneManager { return $this->lm; }
            protected function acquisitionManager(): AcquisitionManager { return $this->am; }
            protected function acquisitionProcess(): AcquisitionProcess { return $this->p; }
            protected function equipementManager(): EquipementManager { return $this->em; }
            protected function referenceGenerator(): ReferenceGenerator { return new ReferenceGenerator(); }
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    public function testAnalyserListeToutesLesLignesAvecConformite(): void
    {
        $analyse = $this->service->analyser($this->fichier());

        self::assertSame([2, 3, 4, 5], array_column($analyse['lignes'], 'ligne'));
        self::assertSame([true, true, false, true], array_column($analyse['lignes'], 'conforme'));
        self::assertSame('Mousquetons', $analyse['lignes'][1]['categorie']);
    }

    public function testEnrichirProposeLibelleEtCodeSansCollision(): void
    {
        $enrichi = $this->service->enrichirCategories(['Cordes', 'Mousquetons', 'Mousquetons HMS']);

        self::assertNotNull($enrichi['categories'][0]['existante']);
        self::assertSame('MOU', $enrichi['categories'][1]['code_propose']);
        self::assertSame('MO1', $enrichi['categories'][2]['code_propose'], 'Pas de collision entre propositions');
        self::assertSame('Mousquetons HMS', $enrichi['categories'][2]['libelle_propose']);
    }

    public function testSeulesLesLignesCocheesSontImportees(): void
    {
        $rapport = $this->service->import($this->fichier(), 1, $this->mapping(), [], [2, 5]);

        self::assertNull($rapport['erreur_globale']);
        self::assertSame(2, $rapport['lignes_lues']);
        self::assertSame(2, $rapport['lignes_exclues']);
        self::assertSame(['Corde 10mm', 'Casque'], array_column($this->lignesSauvees, 'designation'));
    }

    public function testSelectionVideRefusee(): void
    {
        $rapport = $this->service->import($this->fichier(), 1, $this->mapping(), [], []);

        self::assertStringContainsString('Aucune ligne', (string) $rapport['erreur_globale']);
        self::assertSame([], $this->lignesSauvees);
    }

    public function testCategorieCreeeAvecNomEtCodeChoisis(): void
    {
        $this->service->import($this->fichier(), 1, $this->mapping(), [
            'Mousquetons' => ['cible' => 'nouvelle', 'libelle' => 'Connecteurs', 'code' => 'cnx'],
        ], [3]);

        $creee = end($this->categories);
        self::assertSame('Connecteurs', $creee['libelle']);
        self::assertSame('CNX', $creee['code'], 'Code normalisé en majuscules');
        self::assertSame($creee['id'], (int) $this->lignesSauvees[0]['categorie_id']);
    }

    public function testCodeDejaPrisRemplaceParCodeAutomatiqueAvecNote(): void
    {
        $rapport = $this->service->import($this->fichier(), 1, $this->mapping(), [
            'Mousquetons' => ['cible' => 'nouvelle', 'libelle' => 'Connecteurs', 'code' => 'COR'],
        ], [3]);

        $creee = end($this->categories);
        self::assertNull($creee['code']);
        self::assertStringContainsString('déjà utilisé', implode(' ', $rapport['notes']));
    }

    public function testCategorieCreeeAffecteeAUneAutreLigne(): void
    {
        // La ligne 5 (Casque, catégorie « Casques » existante) est
        // affectée à la nouvelle catégorie de « Mousquetons ».
        $this->service->import($this->fichier(), 1, $this->mapping(), [
            'Mousquetons' => ['cible' => 'nouvelle', 'libelle' => 'Connecteurs', 'code' => 'CNX'],
        ], [3, 5], [5 => 'v:Mousquetons']);

        self::assertCount(4, $this->categories, 'Une seule catégorie créée');
        $creee = end($this->categories);
        self::assertSame(
            [$creee['id'], $creee['id']],
            array_map('intval', array_column($this->lignesSauvees, 'categorie_id'))
        );
    }

    public function testLigneAffecteeAUneCategorieExistante(): void
    {
        $this->service->import($this->fichier(), 1, $this->mapping(), [], [2], [2 => '2']);

        self::assertSame(2, (int) $this->lignesSauvees[0]['categorie_id']);
    }

    public function testRegroupementDeDeuxCategoriesACreer(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;date_achat',
            'M1;Mousquetons;2020',
            'M2;Mousqueton;2020',
            '',
        ]));

        $this->service->import($fichier, 1, $this->mapping(), [
            'Mousquetons' => ['cible' => 'nouvelle', 'libelle' => 'Connecteurs', 'code' => 'CNX'],
            'Mousqueton' => ['cible' => 'v:Mousquetons'],
        ]);

        self::assertCount(4, $this->categories, 'Une seule catégorie créée pour les deux valeurs');
        $ids = array_map('intval', array_column($this->lignesSauvees, 'categorie_id'));
        self::assertSame($ids[0], $ids[1]);
    }

    public function testRegroupementCirculaireSansBoucleInfinie(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;date_achat',
            'M1;A1;2020',
            '',
        ]));

        $this->service->import($fichier, 1, $this->mapping(), [
            'A1' => ['cible' => 'v:B1'],
            'B1' => ['cible' => 'v:A1'],
        ]);

        self::assertCount(1, $this->lignesSauvees);
    }

    // ==================================================================
    // Stock max / stock actuel (décision produit 2026-10-20)
    // ==================================================================

    public function testAnalyserProposeLesNombres(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;stock théorique;stock réel',
            'A;Cordes;5;3',   // les deux
            'B;Cordes;4;',    // max seul → actuel = max
            'C;Cordes;;2',    // actuel seul → max = actuel
            'D;Cordes;;',     // aucun → 1 / 1
            'E;Cordes;abc;',  // invalide → à saisir
            '',
        ]));

        $analyse = $this->service->analyser($fichier);

        self::assertSame(['5', '4', '2', '1', ''], array_column($analyse['lignes'], 'nombre'));
        self::assertSame(['3', '4', '2', '1', ''], array_column($analyse['lignes'], 'nombre_actuel'));
    }

    public function testStockActuelReporteSurLeLot(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;nombre;nombre_actuel',
            'Lot A;Cordes;5;3',
            'Lot B;Cordes;4;',
            'Unite;Cordes;;',
            '',
        ]));

        $rapport = $this->service->import($fichier, 1, [
            0 => 'designation', 1 => 'categorie', 2 => 'nombre', 3 => 'nombre_actuel',
        ]);

        self::assertSame([], $rapport['lignes_en_erreur']);
        self::assertSame([5, 4, 1], array_column($this->lignesSauvees, 'nombre'));
        self::assertSame([3, 4, null], array_column($this->lignesSauvees, 'nombre_actuel'));
        self::assertSame([1, 1, 0], array_column($this->lignesSauvees, 'regrouper_en_lot'));
    }

    public function testStockActuelSuperieurAuMaxRefuse(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;nombre;nombre_actuel',
            'Lot;Cordes;2;5',
            '',
        ]));

        $rapport = $this->service->import($fichier, 1, [
            0 => 'designation', 1 => 'categorie', 2 => 'nombre', 3 => 'nombre_actuel',
        ]);

        self::assertCount(1, $rapport['lignes_en_erreur']);
        self::assertStringContainsString('stock actuel', $rapport['lignes_en_erreur'][0]['message']);
    }

    public function testNombresSaisisAvantImportRemplacentLeFichier(): void
    {
        // Ligne 2 : nombre vide dans le fichier, saisi 6 / 4 à l'analyse ;
        // ligne 3 : nombre invalide dans le fichier, corrigé à 2.
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;nombre',
            'Lot;Cordes;',
            'Autre;Cordes;abc',
            '',
        ]));

        $rapport = $this->service->import(
            $fichier,
            1,
            [0 => 'designation', 1 => 'categorie', 2 => 'nombre'],
            [],
            null,
            [],
            [2 => ['nombre' => '6', 'nombre_actuel' => '4'], 3 => ['nombre' => '2']]
        );

        self::assertSame([], $rapport['lignes_en_erreur']);
        self::assertSame([6, 2], array_column($this->lignesSauvees, 'nombre'));
        self::assertSame([4, 2], array_column($this->lignesSauvees, 'nombre_actuel'));
    }

    // ==================================================================
    // Durée de vie limitée (décision produit 2026-10-20)
    // ==================================================================

    public function testDureeLimiteeSansDateAchatRefusee(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;date_achat;date_peremption',
            'Harnais A;HAR;;',          // ligne 2 : refusée
            'Harnais B;HAR;2019-05-10;', // ligne 3 : fin de vie 2029-05-31 (fin de mois)
            'Harnais C;HAR;2019;2027',   // ligne 4 : fin de vie conservée
            'Corde;Cordes;;',            // ligne 5 : pas limitée → date du jour
            '',
        ]));

        $rapport = $this->service->import($fichier, 1, $this->mappingDates());

        self::assertSame(
            [['ligne' => 2, 'message' => AcquisitionImportService::MESSAGE_DATE_ACHAT_LIMITEE]],
            $rapport['lignes_en_erreur']
        );
        self::assertSame(['Harnais B', 'Harnais C', 'Corde'], array_column($this->lignesSauvees, 'designation'));
        self::assertSame(['2029-05-31', '2027-12-31', null], array_column($this->lignesSauvees, 'date_peremption'));
    }

    public function testDatesSaisiesAvantImport(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;date_achat;date_peremption',
            'Harnais A;HAR;;',
            'Harnais B;HAR;2019;',
            '',
        ]));

        $rapport = $this->service->import(
            $fichier,
            1,
            $this->mappingDates(),
            [],
            null,
            [],
            [],
            [
                2 => ['date_achat' => '2021-03-15'],                     // fin de vie par défaut
                3 => ['date_achat' => '2019-01-01', 'date_peremption' => '2030-06-30'],
            ]
        );

        self::assertSame([], $rapport['lignes_en_erreur']);
        self::assertSame(['2031-03-31', '2030-06-30'], array_column($this->lignesSauvees, 'date_peremption'));
    }

    public function testCategorieACreerADureeLimitee(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;date_achat;date_peremption',
            'Longe A;Longes;;',
            'Longe B;Longes;2020;',
            '',
        ]));

        $rapport = $this->service->import($fichier, 1, $this->mappingDates(), [
            'Longes' => ['cible' => 'nouvelle', 'libelle' => 'Longes', 'code' => 'LON', 'duree_max' => '1'],
        ]);

        self::assertCount(1, $rapport['lignes_en_erreur']);
        $creee = end($this->categories);
        self::assertSame(1, $creee['duree_max_utilisation']);
        self::assertSame(['2030-01-31'], array_column($this->lignesSauvees, 'date_peremption'), 'Année seule → 01/01 → +10 ans fin de mois');
    }

    public function testLigneAffecteeAUneCategorieLimitee(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;date_achat;date_peremption',
            'Corde;Cordes;;',
            '',
        ]));

        $rapport = $this->service->import($fichier, 1, $this->mappingDates(), [], null, [2 => '3']);

        self::assertSame(AcquisitionImportService::MESSAGE_DATE_ACHAT_LIMITEE, $rapport['lignes_en_erreur'][0]['message']);
    }

    public function testMarquerDureeDeVieLimitee(): void
    {
        $fichier = $this->csv(implode("\n", [
            'designation;categorie;date_achat;date_peremption',
            'Harnais A;HAR;;',
            'Harnais B;HAR;2019;',
            'Corde;Cordes;;',
            '',
        ]));

        $analyse = $this->service->analyser($fichier);
        $enrichi = $this->service->enrichirCategories($analyse['categories_fichier']);
        $analyse['categories'] = $enrichi['categories'];
        $analyse = $this->service->marquerDureeDeVieLimitee($analyse);

        self::assertSame([true, true, false], array_column($analyse['lignes'], 'limitee'));
        self::assertSame([false, true, true], array_column($analyse['lignes'], 'conforme'));
        self::assertSame(['', '2029-01-31', ''], array_column($analyse['lignes'], 'date_peremption'));
        self::assertSame([2], array_column($analyse['lignes_non_conformes'], 'ligne'));
    }

    /** @return array<int, string> */
    private function mappingDates(): array
    {
        return [0 => 'designation', 1 => 'categorie', 2 => 'date_achat', 3 => 'date_peremption'];
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /** @return array<int, string> */
    private function mapping(): array
    {
        return [0 => 'designation', 1 => 'categorie', 2 => 'date_achat'];
    }

    private function fichier(): string
    {
        return $this->csv(implode("\n", [
            'designation;categorie;date_achat',
            'Corde 10mm;Cordes;2020',      // ligne 2
            'Mousqueton;Mousquetons;2020', // ligne 3 (catégorie inconnue)
            ';Cordes;2020',                // ligne 4 (non conforme)
            'Casque;Casques;2020',         // ligne 5
            '',
        ]));
    }

    private function csv(string $contenu): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epitest_') . '.csv';
        file_put_contents($path, $contenu);
        $this->tempFiles[] = $path;
        $this->tempFiles[] = substr($path, 0, -4);
        return $path;
    }
}
