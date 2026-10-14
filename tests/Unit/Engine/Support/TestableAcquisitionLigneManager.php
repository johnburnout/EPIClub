<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;

/**
 * [REFACTOR #72 / #80] Sous-classe de test de AcquisitionLigneManager.
 *
 * Surcharge le constructeur pour injecter un \PDO mocké sans ouvrir
 * de vraie connexion.
 *
 * [REFACTOR #72] Expose une surcharge acquisitionManager() pour les
 * tests de delete().
 *
 * [REFACTOR #80 - Dette 10] Expose une surcharge categorieManager()
 * pour permettre le comptage de requêtes en Unit dans
 * findByAcquisition() (#72) et findId() (#79).
 */
final class TestableAcquisitionLigneManager extends AcquisitionLigneManager
{
    private ?AcquisitionManager $acquisitionManagerMock = null;
    private ?CategorieManager $categorieManagerMock = null;

    public function __construct(\PDO $pdo)
    {
        $this->db = $pdo;
    }

    public function withAcquisitionManager(AcquisitionManager $manager): self
    {
        $this->acquisitionManagerMock = $manager;
        return $this;
    }

    public function withCategorieManager(CategorieManager $manager): self
    {
        $this->categorieManagerMock = $manager;
        return $this;
    }

    protected function acquisitionManager(): AcquisitionManager
    {
        if ($this->acquisitionManagerMock !== null) {
            return $this->acquisitionManagerMock;
        }
        return parent::acquisitionManager();
    }

    protected function categorieManager(): CategorieManager
    {
        if ($this->categorieManagerMock !== null) {
            return $this->categorieManagerMock;
        }
        return parent::categorieManager();
    }
}