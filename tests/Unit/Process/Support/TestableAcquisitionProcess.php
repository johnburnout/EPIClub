<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Process\Support;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Process\AcquisitionProcess;

/**
 * [REFACTOR VAGUE 9] Sous-classe de test d'AcquisitionProcess.
 *
 * Permet d'injecter les 5 managers mockés (via les factories protected)
 * et évite ainsi l'ouverture PDO déclenchée par
 * AbstractManager::__construct().
 *
 * ⚠️ Les managers sont mockés avec disableOriginalConstructor()
 *    (MockBuilder direct, car createMock() est protected en PHPUnit 11).
 */
final class TestableAcquisitionProcess extends AcquisitionProcess
{
    public function __construct(
        private readonly AcquisitionManager $acquisitionManager,
        private readonly AcquisitionLigneManager $acquisitionLigneManager,
        private readonly FournisseurManager $fournisseurManager,
        private readonly CategorieManager $categorieManager,
        private readonly EquipementManager $equipementManager,
    ) {}

    // ==================================================================
    // Surcharges des factories (injection des mocks)
    // ==================================================================

    protected function acquisitionManager(): AcquisitionManager
    {
        return $this->acquisitionManager;
    }

    protected function acquisitionLigneManager(): AcquisitionLigneManager
    {
        return $this->acquisitionLigneManager;
    }

    protected function fournisseurManager(): FournisseurManager
    {
        return $this->fournisseurManager;
    }

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManager;
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManager;
    }
}