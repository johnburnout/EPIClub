<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\ControleManager;

/**
 * Sous-classe de test de ControleManager : injecte un \PDO mocké sans ouvrir
 * de vraie connexion (AbstractManager::__construct() ouvre PDO).
 */
final class TestableControleManager extends ControleManager
{
    public function __construct(\PDO $pdo)
    {
        // Ne PAS appeler parent::__construct() : il ouvrirait MySQL.
        $this->db = $pdo;
    }
}
