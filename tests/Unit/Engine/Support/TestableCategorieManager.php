<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\CategorieManager;

/**
 * [REFACTOR #72] Sous-classe de test de CategorieManager.
 *
 * Surcharge le constructeur pour injecter un \PDO mocké sans ouvrir
 * de vraie connexion (AbstractManager::__construct() ouvre PDO).
 *
 * Pattern aligné sur TestableFournisseurManager.
 */
final class TestableCategorieManager extends CategorieManager
{
    public function __construct(\PDO $pdo)
    {
        $this->db = $pdo;
    }
}