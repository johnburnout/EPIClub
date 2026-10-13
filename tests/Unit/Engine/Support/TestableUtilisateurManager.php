<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\UtilisateurManager;

/**
 * [REFACTOR #71] Sous-classe de test de UtilisateurManager.
 *
 * Surcharge le constructeur pour injecter un \PDO mocké sans ouvrir
 * de vraie connexion (AbstractManager::__construct() ouvre PDO).
 *
 * Pattern aligné sur TestableAcquisitionManager.
 */
final class TestableUtilisateurManager extends UtilisateurManager
{
    public function __construct(\PDO $pdo)
    {
        // Ne PAS appeler parent::__construct() : il ouvrirait une
        // vraie connexion MySQL.
        $this->db = $pdo;
    }
}