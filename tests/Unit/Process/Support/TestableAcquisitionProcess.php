<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Process\Support;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Process\AcquisitionProcess;
use Epiclub\Tests\Unit\Domain\Support\TestableReferenceGenerator;

/**
 * [REFACTOR VAGUE 9] Sous-classe de test d'AcquisitionProcess.
 *
 * Permet d'injecter les 5 managers mockés (via les factories protected)
 * et évite ainsi l'ouverture PDO déclenchée par
 * AbstractManager::__construct().
 *
 * ⚠️ Les managers sont mockés avec disableOriginalConstructor()
 *    (MockBuilder direct, car createMock() est protected en PHPUnit 11).
 */
final class TestableAcquisitionProcess extends AcquisitionProcess
{
    public function __construct(
        private readonly AcquisitionManager $acquisitionManager,
        private readonly AcquisitionLigneManager $acquisitionLigneManager,
        private readonly FournisseurManager $fournisseurManager,
        private readonly CategorieManager $categorieManager,
        private readonly EquipementManager $equipementManager,
        private readonly ?ReferenceGenerator $referenceGeneratorMock = null,
    ) {}

    /**
     * Sans doublure fournie, le générateur de références utilise les
     * managers mockés de ce process (aucune connexion PDO) : avant, le
     * générateur réel interrogeait la base locale et les tests
     * dépendaient de son contenu (catégorie id=3, cf. CI MySQL vierge).
     */
    protected function referenceGenerator(): ReferenceGenerator
    {
        if ($this->referenceGeneratorMock !== null) {
            return $this->referenceGeneratorMock;
        }
        // Doublures propres au générateur (distinctes des mocks du
        // process, pour ne pas fausser leurs attentes d'appels).
        $generateur = new TestableReferenceGenerator();
        $generateur->categorieManagerMock = new class extends CategorieManager {
            public function __construct()
            {
            }

            public function findId(int $id)
            {
                return ['id' => $id, 'libelle' => 'Catégorie test', 'code' => 'TST'];
            }
        };
        $generateur->equipementManagerMock = new class extends EquipementManager {
            public function __construct()
            {
            }

            public function findReferencesMatching(string $regexp): array
            {
                return [];
            }
        };
        $generateur->acquisitionLigneManagerMock = new class extends AcquisitionLigneManager {
            public function __construct()
            {
            }

            public function findReferencesMatching(string $regexp): array
            {
                return [];
            }
        };
        $generateur->clubManagerMock = new class extends ClubManager {
            public function __construct()
            {
                // pas de connexion : paramètres du club vides (modèle par défaut)
            }

            public function findParameters()
            {
                return [];
            }
        };
        return $generateur;
    }

    // ==================================================================
    // Surcharges des factories (injection des mocks)
    // ==================================================================

    protected function acquisitionManager(): AcquisitionManager
    {
        return $this->acquisitionManager;
    }

    protected function acquisitionLigneManager(): AcquisitionLigneManager
    {
        return $this->acquisitionLigneManager;
    }

    protected function fournisseurManager(): FournisseurManager
    {
        return $this->fournisseurManager;
    }

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManager;
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManager;
    }
}