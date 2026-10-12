<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\FournisseurController;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Request;

final class TestableFournisseurController extends FournisseurController
{
    public function __construct(
        Session $session,
        private readonly FournisseurManager $fournisseurManager,
    ) {
        parent::__construct($session);
    }

    protected function fournisseurManager(): FournisseurManager
    {
        return $this->fournisseurManager;
    }

    protected function updateLastActivity(): void
    {
        // no-op — évite new UtilisateurManager() qui ouvre PDO.
    }

    protected function validateCsrf(Request $request): void
    {
        // no-op — CSRF est testé isolément dans CsrfValidatorTest.
    }
}