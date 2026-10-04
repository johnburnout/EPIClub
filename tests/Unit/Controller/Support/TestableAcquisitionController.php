<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AcquisitionController;
use Epiclub\Engine\FactureUploader;
use Epiclub\Engine\HandlerResult;
use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Request;

/**
 * [REFACTOR VAGUE 7] Sous-classe de test d'AcquisitionController.
 *
 * Expose les handlers protégés et permet d'injecter un FactureUploader
 * (via surcharge de la factory `factureUploader()` passée en protected).
 *
 * ⚠️ `validator()` n'est PAS surchargé : il n'est pas utilisé par
 * `handleCreateAction` (les handlers testés ici court-circuitent avant).
 * Si un futur handler utilise le validator, on ajoutera la surcharge
 * (mais `AcquisitionValidator` étant final, il faudra une vraie instance
 * ou un refactor d'interface — voir Vague 8).
 */
final class TestableAcquisitionController extends AcquisitionController
{
    public function __construct(
        Session $session,
        private readonly FactureUploader $uploader,
    ) {
        parent::__construct($session);
    }

    protected function factureUploader(): FactureUploader
    {
        return $this->uploader;
    }

    // ==================================================================
    // Expose les handlers protégés
    // ==================================================================

    public function callHandleCreateAction(Request $request, array $acquisition): HandlerResult
    {
        return $this->handleCreateAction($request, $acquisition);
    }
}