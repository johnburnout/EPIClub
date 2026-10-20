<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\ReferenceGenerator;

use Epiclub\Controller\AcquisitionLineController;
use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\Session;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\Request;

/**
 * [#99 — Dette 19] Sous-classe de test d'AcquisitionLineController.
 *
 * Dernier contrôleur migré Vague 11 : les 4 dépendances en dur
 * (AcquisitionLigneManager, CategorieManager, AcquisitionManager,
 * AcquisitionProcess) sont injectées via les factories protected.
 */
final class TestableAcquisitionLineController extends AcquisitionLineController
{
    public function __construct(
        Session $session,
        private ?AcquisitionLigneManager $acquisitionLigneManagerMock = null,
        private ?CategorieManager $categorieManagerMock = null,
        private ?AcquisitionManager $acquisitionManagerMock = null,
        private ?AcquisitionProcess $acquisitionProcessMock = null,
    ) {
        parent::__construct($session);
    }

    protected function updateLastActivity(): void
    {
        // no-op : évite new UtilisateurManager() → PDO
    }

    protected function validateCsrf(Request $request): void
    {
        // no-op : CSRF testé isolément dans CsrfValidatorTest.
    }

    protected function acquisitionLigneManager(): AcquisitionLigneManager
    {
        return $this->acquisitionLigneManagerMock ?? parent::acquisitionLigneManager();
    }

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManagerMock ?? parent::categorieManager();
    }

    protected function acquisitionManager(): AcquisitionManager
    {
        return $this->acquisitionManagerMock ?? parent::acquisitionManager();
    }

    protected function acquisitionProcess(): AcquisitionProcess
    {
        return $this->acquisitionProcessMock ?? parent::acquisitionProcess();
    }

    /** [Référencement] Doublure injectable ; par défaut : référence libre, pas de proposition. */
    public ?ReferenceGenerator $referenceGeneratorMock = null;

    protected function referenceGenerator(): ReferenceGenerator
    {
        return $this->referenceGeneratorMock ?? new class extends ReferenceGenerator {
            public function compteurSiConforme(string $reference, int $categorieId, int $annee): ?int
            {
                return null;
            }

            public function suggest(string $libelleCategorie, int $annee): string
            {
                return '';
            }
        };
    }
}
