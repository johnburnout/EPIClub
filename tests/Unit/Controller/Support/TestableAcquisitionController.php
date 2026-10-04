<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AcquisitionController;
use Epiclub\Engine\FactureUploaderInterface;
use Epiclub\Engine\HandlerResult;
use Epiclub\Engine\Session;
use Epiclub\Domain\AcquisitionValidatorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * [REFACTOR VAGUE 7 → 8] Sous-classe de test d'AcquisitionController.
 *
 * Expose les handlers protégés et permet d'injecter un
 * FactureUploaderInterface (mockable depuis Vague 8).
 *
 * ⚠️ `validator()` n'est PAS surchargé pour l'instant : il sera
 * traité à l'Étape 2 de la Vague 8 (AcquisitionValidatorInterface).
 */
final class TestableAcquisitionController extends AcquisitionController
{
    public function __construct(
        Session $session,
        private readonly FactureUploaderInterface $uploader,
        private readonly AcquisitionValidatorInterface $validator,
    ) {
    parent::__construct($session);
    }
    
    protected function validator(): AcquisitionValidatorInterface
    {
        return $this->validator;
    }

    protected function factureUploader(): FactureUploaderInterface
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

    public function callHandleDeleteAction(array $acquisition): HandlerResult
    {
        return $this->handleDeleteAction($acquisition);
    }
    
    public function callHandleAddLigneAction(Request $request, array $acquisition): HandlerResult
    {
        return $this->handleAddLigneAction($request, $acquisition);
    }
}