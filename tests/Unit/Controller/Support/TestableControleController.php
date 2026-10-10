<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\ControleController;
use Epiclub\Domain\CategorieManager;              // ← NOUVEAU
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EmplacementManager;            // ← NOUVEAU
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
        private readonly CategorieManager $categorieManager,         // ← NOUVEAU
        private readonly EmplacementManager $emplacementManager,     // ← NOUVEAU
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

    // ═══════════════════════════════════════════════════════════════
    // [VAGUE 11 — issue #61] Nouvelles surcharges
    // ═══════════════════════════════════════════════════════════════

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManager;
    }

    protected function emplacementManager(): EmplacementManager
    {
        return $this->emplacementManager;
    }

    // ═══════════════════════════════════════════════════════════════

    protected function configProvider(): ConfigProvider
    {
        return $this->configProvider ?? parent::configProvider();
    }

    protected function updateLastActivity(): void
    {
        // no-op
    }
}