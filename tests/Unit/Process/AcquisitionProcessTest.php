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
}