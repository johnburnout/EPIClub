<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\UtilisateurController;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;

final class TestableUtilisateurController extends UtilisateurController
{
    public function __construct(
        Session $session,
        private readonly UtilisateurManager $utilisateurManager,
    ) {
        parent::__construct($session);
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return $this->utilisateurManager;
    }
}