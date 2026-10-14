<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\UtilisateurManager;

/**
 * [REFACTOR #71 / #80] Sous-classe de test d'AcquisitionManager.
 *
 * Surcharge le constructeur pour injecter un \PDO mocké sans ouvrir
 * de vraie connexion (AbstractManager::__construct() ouvre PDO).
 *
 * [REFACTOR #80 - Dette 10] Expose les surcharges des factories
 * fournisseurManager() et utilisateurManager() pour permettre le
 * mock et le comptage de requêtes en Unit (prérequis identifié en #79).
 *
 * Pattern aligné sur TestableAbstractManager
 * (AbstractManagerBuildWhereClauseTest.php).
 */
final class TestableAcquisitionManager extends AcquisitionManager
{
    public function __construct(
        \PDO $pdo,
        private ?FournisseurManager $fournisseurManagerMock = null,
        private ?UtilisateurManager $utilisateurManagerMock = null,
    ) {
        // Ne PAS appeler parent::__construct() : il ouvrirait une
        // vraie connexion MySQL.
        $this->db = $pdo;
    }

    public function withFournisseurManager(FournisseurManager $manager): self
    {
        $this->fournisseurManagerMock = $manager;
        return $this;
    }

    public function withUtilisateurManager(UtilisateurManager $manager): self
    {
        $this->utilisateurManagerMock = $manager;
        return $this;
    }

    protected function fournisseurManager(): FournisseurManager
    {
        return $this->fournisseurManagerMock ?? parent::fournisseurManager();
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return $this->utilisateurManagerMock ?? parent::utilisateurManager();
    }
}