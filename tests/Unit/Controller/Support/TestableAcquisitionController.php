<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AcquisitionController;
use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\AcquisitionValidatorInterface;
use Epiclub\Engine\FactureUploaderInterface;
use Epiclub\Engine\HandlerResult;
use Epiclub\Engine\Session;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\Request;

/**
 * [REFACTOR VAGUE 8] Sous-classe de test d'AcquisitionController.
 *
 * Expose les handlers protégés et permet d'injecter :
 *  - FactureUploaderInterface (Vague 8)
 *  - AcquisitionValidatorInterface (Vague 8)
 *  - Les 5 managers + AcquisitionProcess (Vague 8, étape 2)
 *
 * ⚠️ Tous les managers sont mockés avec disableOriginalConstructor()
 *    pour éviter l'ouverture d'une connexion PDO via AbstractManager.
 */
final class TestableAcquisitionController extends AcquisitionController
{
    public function __construct(
        Session $session,
        private readonly FactureUploaderInterface $uploader,
        private readonly AcquisitionValidatorInterface $validator,
        private readonly AcquisitionManager $acquisitionManager,
        private readonly AcquisitionLigneManager $acquisitionLigneManager,
        private readonly FournisseurManager $fournisseurManager,
        private readonly CategorieManager $categorieManager,
        private readonly EquipementManager $equipementManager,
        private readonly AcquisitionProcess $acquisitionProcess,
    ) {
        parent::__construct($session);
    }

    // ==================================================================
    // Surcharges des factories (injection des mocks)
    // ==================================================================

    protected function validator(): AcquisitionValidatorInterface
    {
        return $this->validator;
    }

    protected function factureUploader(): FactureUploaderInterface
    {
        return $this->uploader;
    }

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

    protected function acquisitionProcess(): AcquisitionProcess
    {
        return $this->acquisitionProcess;
    }

    // ==================================================================
    // Expose les handlers protégés
    // ==================================================================

    public function callHandleCreateAction(Request $request, array $acquisition): HandlerResult
    {
        return $this->handleCreateAction($request, $acquisition);
    }

    public function callHandleUpdateAction(Request $request, array $acquisition): HandlerResult
    {
        return $this->handleUpdateAction($request, $acquisition);
    }

    public function callHandleAddLigneAction(Request $request, array $acquisition): HandlerResult
    {
        return $this->handleAddLigneAction($request, $acquisition);
    }

    public function callHandleDeleteAction(array $acquisition): HandlerResult
    {
        return $this->handleDeleteAction($acquisition);
    }
}