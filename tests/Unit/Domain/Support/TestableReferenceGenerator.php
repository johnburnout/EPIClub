<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain\Support;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ReferenceGenerator;

/**
 * Sous-classe de test pour ReferenceGenerator : remplace les trois
 * factories de managers par des doublures injectées (pas de PDO réel).
 */
final class TestableReferenceGenerator extends ReferenceGenerator
{
    public ?CategorieManager $categorieManagerMock = null;
    public ?ClubManager $clubManagerMock = null;
    public ?EquipementManager $equipementManagerMock = null;

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManagerMock ?? throw new \LogicException('categorieManagerMock non injecté');
    }

    protected function clubManager(): ClubManager
    {
        return $this->clubManagerMock ?? throw new \LogicException('clubManagerMock non injecté');
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManagerMock ?? throw new \LogicException('equipementManagerMock non injecté');
    }
}
