<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\EquipementController;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;

final class TestableEquipementController extends EquipementController
{
    public function __construct(
        Session $session,
        private readonly EquipementManager $equipementManager,
        private readonly CategorieManager $categorieManager,
        private readonly EmplacementManager $emplacementManager,
        private readonly AcquisitionManager $acquisitionManager,
    ) {
        parent::__construct($session);
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManager;
    }

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManager;
    }

    protected function emplacementManager(): EmplacementManager
    {
        return $this->emplacementManager;
    }

    protected function acquisitionManager(): AcquisitionManager
    {
        return $this->acquisitionManager;
    }
}