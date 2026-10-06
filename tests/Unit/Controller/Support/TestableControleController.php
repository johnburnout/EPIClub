<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\ControleController;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Request;

final class TestableControleController extends ControleController
{
    public function __construct(
        Session $session,
        private readonly ControleManager $controleManager,
        private readonly ControleLigneManager $controleLigneManager,
        private readonly EquipementManager $equipementManager,
        private readonly UtilisateurManager $utilisateurManager,
    ) {
        parent::__construct($session);
    }

    protected function controleManager(): ControleManager
    {
        return $this->controleManager;
    }

    protected function controleLigneManager(): ControleLigneManager
    {
        return $this->controleLigneManager;
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManager;
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return $this->utilisateurManager;
    }
}