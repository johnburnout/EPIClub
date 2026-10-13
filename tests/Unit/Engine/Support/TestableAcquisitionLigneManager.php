<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;

/**
 * [REFACTOR #72] Sous-classe de test de AcquisitionLigneManager.
 *
 * Surcharge le constructeur pour injecter un \PDO mocké sans ouvrir
 * de vraie connexion.
 *
 * Expose une surcharge de la factory acquisitionManager() pour
 * injecter un mock d'AcquisitionManager dans les tests de delete().
 */
final class TestableAcquisitionLigneManager extends AcquisitionLigneManager
{
    private ?AcquisitionManager $acquisitionManagerMock = null;

    public function __construct(\PDO $pdo)
    {
        $this->db = $pdo;
    }

    public function withAcquisitionManager(AcquisitionManager $manager): self
    {
        $this->acquisitionManagerMock = $manager;
        return $this;
    }

    protected function acquisitionManager(): AcquisitionManager
    {
        if ($this->acquisitionManagerMock !== null) {
            return $this->acquisitionManagerMock;
        }
        return parent::acquisitionManager();
    }
}