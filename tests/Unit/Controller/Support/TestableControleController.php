<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\ControleController;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\Session;

final class TestableControleController extends ControleController
{
    public function __construct(
        Session $session,
        private readonly ControleManager $controleManager,
        private readonly ControleLigneManager $controleLigneManager,
        private readonly EquipementManager $equipementManager,
        private readonly UtilisateurManager $utilisateurManager,
        private readonly ?ConfigProvider $configProvider = null,
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

    /**
     * [REFACTOR #52] Surcharge de la factory ConfigProvider.
     *
     * Sans surcharge, AbstractController::configProvider() lit le
     * .env.local.php réel à la racine projet. En test Unit, on injecte
     * une ConfigProvider pointant sur tests/Fixtures/env.test.php.
     */
    protected function configProvider(): ConfigProvider
    {
        return $this->configProvider ?? parent::configProvider();
    }

    /**
     * [REFACTOR #52] No-op en test Unit.
     *
     * AbstractController::updateLastActivity() instancie un
     * UtilisateurManager concret → ouverture PDO à chaque construction.
     * On neutralise pour garder les tests Unit sans dépendance BDD.
     *
     * ⚠️ Aucun test n'asserte le comportement de updateLastActivity(),
     * donc cette surcharge est iso-comportement observable.
     */
    protected function updateLastActivity(): void
    {
        // no-op
    }
}