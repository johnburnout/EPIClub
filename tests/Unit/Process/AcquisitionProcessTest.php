<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Process;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Tests\Unit\Process\Support\TestableAcquisitionProcess;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * [VAGUE 9] Tests Unit d'AcquisitionProcess.
 *
 * Toutes les dépendances (5 managers) sont mockées avec
 * disableOriginalConstructor() pour éviter l'ouverture PDO
 * d'AbstractManager::__construct().
 *
 * ⚠️ PHPUnit 11 : createMock() est protected → on utilise MockBuilder
 *    direct dans les helpers makeXxxManager().
 */
final class AcquisitionProcessTest extends TestCase
{
    // ==================================================================
    // acquisition_process()
    // ==================================================================

    public function testAcquisitionProcessReusesExistingFournisseur(): void
    {
        $fournisseurManager = $this->makeFournisseurManagerMock();
        $fournisseurManager->expects(self::once())
            ->method('findOneByCriteria')
            ->with(['nom' => 'Fournisseur Existant'])
            ->willReturn(['id' => 7, 'nom' => 'Fournisseur Existant']);
        $fournisseurManager->expects(self::never())->method('save');

        $acquisitionManager = $this->makeAcquisitionManagerMock();
        $acquisitionManager->expects(self::once())
            ->method('save')
            ->willReturn(42);

        $process = $this->makeProcessWith(
            acquisitionManager: $acquisitionManager,
            fournisseurManager: $fournisseurManager,
        );

        $id = $process->acquisition_process([
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Existant',
        ]);

        self::assertSame(42, $id);
    }

    public function testAcquisitionProcessCreatesFournisseurWhenUnknown(): void
    {
        $fournisseurManager = $this->makeFournisseurManagerMock();
        $fournisseurManager->expects(self::once())
            ->method('findOneByCriteria')
            ->willReturn(null);
        $fournisseurManager->expects(self::once())
            ->method('save')
            ->with(['nom' => 'Nouveau Fournisseur'])
            ->willReturn(99);

        $acquisitionManager = $this->makeAcquisitionManagerMock();
        $acquisitionManager->expects(self::once())
            ->method('save')
            ->willReturn(42);

        $process = $this->makeProcessWith(
            acquisitionManager: $acquisitionManager,
            fournisseurManager: $fournisseurManager,
        );

        $id = $process->acquisition_process([
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Nouveau Fournisseur',
        ]);

        self::assertSame(42, $id);
    }

    public function testAcquisitionProcessAppliesDefaultsForMissingKeys(): void
    {
        $fournisseurManager = $this->makeFournisseurManagerMock();
        $fournisseurManager->method('findOneByCriteria')->willReturn(['id' => 5]);

        $acquisitionManager = $this->makeAcquisitionManagerMock();
        $acquisitionManager->expects(self::once())
            ->method('save')
            ->with(self::callback(function (array $acquisition): bool {
                // Vérifie que les defaults sont appliqués
                return $acquisition['est_validee'] === 0
                    && $acquisition['facture_document'] === null
                    && $acquisition['saisie_par'] === null
                    && $acquisition['fournisseur_id'] === 5
                    && !array_key_exists('fournisseur_nom', $acquisition);
            }))
            ->willReturn(42);

        $process = $this->makeProcessWith(
            acquisitionManager: $acquisitionManager,
            fournisseurManager: $fournisseurManager,
        );

        $process->acquisition_process([]);
    }

    public function testAcquisitionProcessReturnsInt(): void
    {
        $fournisseurManager = $this->makeFournisseurManagerMock();
        $fournisseurManager->method('findOneByCriteria')->willReturn(['id' => 5]);

        $acquisitionManager = $this->makeAcquisitionManagerMock();
        // Le manager retourne un string (simulation lastInsertId)
        $acquisitionManager->method('save')->willReturn('42');

        $process = $this->makeProcessWith(
            acquisitionManager: $acquisitionManager,
            fournisseurManager: $fournisseurManager,
        );

        $id = $process->acquisition_process([
            'facture_reference' => 'FAC-001',
            'fournisseur_nom'   => 'Fournisseur',
        ]);

        // Le process doit caster en int
        self::assertSame(42, $id);
    }

    // ==================================================================
    // categorie_process()
    // ==================================================================

    public function testCategorieProcessReusesExistingCategorie(): void
    {
        $categorieManager = $this->makeCategorieManagerMock();
        $categorieManager->expects(self::once())
            ->method('findOneByCriteria')
            ->with(['libelle' => 'outillage'])
            ->willReturn(['id' => 3, 'libelle' => 'Outillage']);
        $categorieManager->expects(self::never())->method('save');

        $process = $this->makeProcessWith(categorieManager: $categorieManager);

        $id = $process->categorie_process(['categorie_libelle' => 'outillage']);

        self::assertSame(3, $id);
    }

    public function testCategorieProcessCreatesCategorieWithUcfirst(): void
    {
        $categorieManager = $this->makeCategorieManagerMock();
        $categorieManager->expects(self::once())
            ->method('findOneByCriteria')
            ->willReturn(null);
        $categorieManager->expects(self::once())
            ->method('save')
            ->with(self::callback(function (array $categorie): bool {
                return $categorie['libelle'] === 'Outillage'
                    && $categorie['description'] === ''
                    && $categorie['image'] === ''
                    && $categorie['est_epi'] === 1;
            }))
            ->willReturn(15);

        $process = $this->makeProcessWith(categorieManager: $categorieManager);

        $id = $process->categorie_process(['categorie_libelle' => 'outillage']);

        self::assertSame(15, $id);
    }

    public function testCategorieProcessReturnsIdFromSave(): void
    {
        $categorieManager = $this->makeCategorieManagerMock();
        $categorieManager->method('findOneByCriteria')->willReturn(null);
        $categorieManager->method('save')->willReturn('7');  // string

        $process = $this->makeProcessWith(categorieManager: $categorieManager);

        $id = $process->categorie_process(['categorie_libelle' => 'test']);

        // Pas de cast int explicite dans le code source, on vérifie
        // que la valeur retournée est bien celle du manager.
        self::assertEquals(7, $id);
    }
    
    // ==================================================================
    // create_equipement_process()
    // ==================================================================
    
    public function testCreateEquipementProcessThrowsWhenLigneNotFound(): void
    {
        $ligneManager = $this->makeAcquisitionLigneManagerMock();
        $ligneManager->expects(self::once())
        ->method('findId')
        ->with(999)
        ->willReturn(null);
        
        $process = $this->makeProcessWith(acquisitionLigneManager: $ligneManager);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("L'acquisition ligne n'existe pas");
        
        $process->create_equipement_process(999);
    }
    
    public function testCreateEquipementProcessThrowsWhenAlreadyGenerated(): void
    {
        $ligneManager = $this->makeAcquisitionLigneManagerMock();
        $ligneManager->expects(self::once())
        ->method('findId')
        ->with(10)
        ->willReturn([
            'id'                  => 10,
            'acquisition_id'      => 42,
            'reference'           => 'REF-001',
            'designation'         => 'Tournevis',
            'categorie_id'        => 3,
            'nombre'              => 5,
            'equipements_generes' => 1,  // ← déjà généré
            'regrouper_en_lot'    => 0,
        ]);
        
        $equipementManager = $this->makeEquipementManagerMock();
        $equipementManager->expects(self::never())->method('save');
        
        $process = $this->makeProcessWith(
        acquisitionLigneManager: $ligneManager,
        equipementManager: $equipementManager,
        );
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Les équipements ont déjà été générés');
        
        $process->create_equipement_process(10);
    }
    
    public function testCreateEquipementProcessCreatesOneEquipementWhenRegrouperEnLot(): void
    {
        $ligneManager = $this->makeAcquisitionLigneManagerMock();
        $ligneManager->expects(self::once())
        ->method('findId')
        ->with(10)
        ->willReturn($this->makeLigneFixture(regrouperEnLot: 1, nombre: 5));
        // Le save final marque equipements_generes=1
        $ligneManager->expects(self::once())
        ->method('save')
        ->with(self::callback(fn(array $l): bool => $l['equipements_generes'] === 1));
        
        $acquisitionManager = $this->makeAcquisitionManagerMock();
        $acquisitionManager->expects(self::once())
        ->method('findId')
        ->with(42)
        ->willReturn(['id' => 42, 'facture_date' => '2025-01-15']);
        
        $categorieManager = $this->makeCategorieManagerMock();
        $categorieManager->expects(self::once())
        ->method('findId')
        ->with(3)
        ->willReturn(['id' => 3, 'est_epi' => 1]);
        
        $equipementManager = $this->makeEquipementManagerMock();
        $equipementManager->method('codeExists')->willReturn(false);
        // Un seul équipement créé (lot)
        $equipementManager->expects(self::once())
        ->method('save')
        ->with(self::callback(function (array $equipement): bool {
            return $equipement['nombre'] === 5
            && str_ends_with($equipement['code'], '-LOT');
        }));
        
        $process = $this->makeProcessWith(
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        categorieManager: $categorieManager,
        equipementManager: $equipementManager,
        );
        
    self::assertTrue($process->create_equipement_process(10));
    }
    
    public function testCreateEquipementProcessCreatesNEquipementsWhenNotRegrouper(): void
    {
        $ligneManager = $this->makeAcquisitionLigneManagerMock();
        $ligneManager->method('findId')->willReturn($this->makeLigneFixture(regrouperEnLot: 0, nombre: 3));
        $ligneManager->expects(self::once())->method('save');
        
        $acquisitionManager = $this->makeAcquisitionManagerMock();
        $acquisitionManager->method('findId')->willReturn(['id' => 42, 'facture_date' => '2025-01-15']);
        
        $categorieManager = $this->makeCategorieManagerMock();
        $categorieManager->method('findId')->willReturn(['id' => 3, 'est_epi' => 1]);
        
        $equipementManager = $this->makeEquipementManagerMock();
        $equipementManager->method('codeExists')->willReturn(false);
        // 3 équipements créés (nombre=3)
        $equipementManager->expects(self::exactly(3))->method('save');
        
        $process = $this->makeProcessWith(
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        categorieManager: $categorieManager,
        equipementManager: $equipementManager,
        );
        
    self::assertTrue($process->create_equipement_process(10));
    }
    
    public function testCreateEquipementProcessAddsSuffixWhenCodeAlreadyExists(): void
    {
        $ligneManager = $this->makeAcquisitionLigneManagerMock();
        $ligneManager->method('findId')->willReturn($this->makeLigneFixture(regrouperEnLot: 0, nombre: 1));
        $ligneManager->method('save');
        
        $acquisitionManager = $this->makeAcquisitionManagerMock();
        $acquisitionManager->method('findId')->willReturn(['id' => 42, 'facture_date' => '2025-01-15']);
        
        $categorieManager = $this->makeCategorieManagerMock();
        $categorieManager->method('findId')->willReturn(['id' => 3, 'est_epi' => 1]);
        
        $equipementManager = $this->makeEquipementManagerMock();
        // 1er code existe → suffixe aléatoire ajouté, puis 2e check → false
        $equipementManager->method('codeExists')
        ->willReturnOnConsecutiveCalls(true, false);
        $equipementManager->expects(self::once())
        ->method('save')
        ->with(self::callback(function (array $equipement): bool {
            // Le code doit avoir un suffixe -XX (2 chiffres) ajouté
            return preg_match('/-\d{2}$/', $equipement['code']) === 1;
        }));
        
        $process = $this->makeProcessWith(
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        categorieManager: $categorieManager,
        equipementManager: $equipementManager,
        );
        
    self::assertTrue($process->create_equipement_process(10));
    }
    
    public function testCreateEquipementProcessUsesCategorieEstEpi(): void
    {
        $ligneManager = $this->makeAcquisitionLigneManagerMock();
        $ligneManager->method('findId')->willReturn($this->makeLigneFixture(regrouperEnLot: 0, nombre: 1));
        $ligneManager->method('save');
        
        $acquisitionManager = $this->makeAcquisitionManagerMock();
        $acquisitionManager->method('findId')->willReturn(['id' => 42, 'facture_date' => '2025-01-15']);
        
        $categorieManager = $this->makeCategorieManagerMock();
        // La catégorie a est_epi = 0
        $categorieManager->method('findId')->willReturn(['id' => 3, 'est_epi' => 0]);
        
        $equipementManager = $this->makeEquipementManagerMock();
        $equipementManager->method('codeExists')->willReturn(false);
        $equipementManager->expects(self::once())
        ->method('save')
        ->with(self::callback(fn(array $e): bool => $e['est_epi'] === 0));
        
        $process = $this->makeProcessWith(
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        categorieManager: $categorieManager,
        equipementManager: $equipementManager,
        );
        
        $process->create_equipement_process(10);
    }
    
    public function testCreateEquipementProcessDefaultsEstEpiToOneWhenCategorieMissing(): void
    {
        $ligneManager = $this->makeAcquisitionLigneManagerMock();
        $ligneManager->method('findId')->willReturn($this->makeLigneFixture(regrouperEnLot: 0, nombre: 1));
        $ligneManager->method('save');
        
        $acquisitionManager = $this->makeAcquisitionManagerMock();
        $acquisitionManager->method('findId')->willReturn(['id' => 42, 'facture_date' => '2025-01-15']);
        
        $categorieManager = $this->makeCategorieManagerMock();
        // Catégorie introuvable → est_epi par défaut = 1
        $categorieManager->method('findId')->willReturn(null);
        
        $equipementManager = $this->makeEquipementManagerMock();
        $equipementManager->method('codeExists')->willReturn(false);
        $equipementManager->expects(self::once())
        ->method('save')
        ->with(self::callback(fn(array $e): bool => $e['est_epi'] === 1));
        
        $process = $this->makeProcessWith(
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        categorieManager: $categorieManager,
        equipementManager: $equipementManager,
        );
        
        $process->create_equipement_process(10);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeProcessWith(
        ?AcquisitionManager $acquisitionManager = null,
        ?AcquisitionLigneManager $acquisitionLigneManager = null,
        ?FournisseurManager $fournisseurManager = null,
        ?CategorieManager $categorieManager = null,
        ?EquipementManager $equipementManager = null,
    ): TestableAcquisitionProcess {
        return new TestableAcquisitionProcess(
            $acquisitionManager ?? $this->makeAcquisitionManagerMock(),
            $acquisitionLigneManager ?? $this->makeAcquisitionLigneManagerMock(),
            $fournisseurManager ?? $this->makeFournisseurManagerMock(),
            $categorieManager ?? $this->makeCategorieManagerMock(),
            $equipementManager ?? $this->makeEquipementManagerMock(),
        );
    }

    private function makeAcquisitionManagerMock(): AcquisitionManager
    {
        return (new MockBuilder($this, AcquisitionManager::class))
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function makeAcquisitionLigneManagerMock(): AcquisitionLigneManager
    {
        return (new MockBuilder($this, AcquisitionLigneManager::class))
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function makeFournisseurManagerMock(): FournisseurManager
    {
        return (new MockBuilder($this, FournisseurManager::class))
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function makeCategorieManagerMock(): CategorieManager
    {
        return (new MockBuilder($this, CategorieManager::class))
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function makeEquipementManagerMock(): EquipementManager
    {
        return (new MockBuilder($this, EquipementManager::class))
            ->disableOriginalConstructor()
            ->getMock();
    }
    
    /**
    * Fixture minimale d'une ligne d'acquisition pour les tests
    * de create_equipement_process().
    *
    * @param int $regrouperEnLot 0 ou 1
    * @param int $nombre         Nombre d'équipements à générer
    * @return array<string,mixed>
    */
    private function makeLigneFixture(int $regrouperEnLot = 0, int $nombre = 1): array
    {
        return [
            'id'                  => 10,
            'acquisition_id'      => 42,
            'reference'           => 'REF-001',
            'designation'         => 'Tournevis',
            'categorie_id'        => 3,
            'nombre'              => $nombre,
            'equipements_generes' => 0,
            'regrouper_en_lot'    => $regrouperEnLot,
        ];
    }
}