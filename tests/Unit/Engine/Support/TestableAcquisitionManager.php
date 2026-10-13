<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\AcquisitionManager;

/**
 * [REFACTOR #71] Sous-classe de test d'AcquisitionManager.
 *
 * Surcharge le constructeur pour injecter un \PDO mocké sans ouvrir
 * de vraie connexion (AbstractManager::__construct() ouvre PDO).
 *
 * Pattern aligné sur TestableAbstractManager
 * (AbstractManagerBuildWhereClauseTest.php).
 */
final class TestableAcquisitionManager extends AcquisitionManager
{
    public function __construct(\PDO $pdo)
    {
        // Ne PAS appeler parent::__construct() : il ouvrirait une
        // vraie connexion MySQL.
        $this->db = $pdo;
    }
}