<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\EmplacementManager;

/**
 * Sous-classe de test de EmplacementManager : injecte un \PDO mocké sans ouvrir
 * de vraie connexion (AbstractManager::__construct() ouvre PDO).
 */
final class TestableEmplacementManager extends EmplacementManager
{
    public function __construct(\PDO $pdo)
    {
        // Ne PAS appeler parent::__construct() : il ouvrirait MySQL.
        $this->db = $pdo;
    }
}
