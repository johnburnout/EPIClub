<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\CategorieManager;            // ← NOUVEAU
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EmplacementManager;          // ← NOUVEAU
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

final class TestableControleControllerBuilder
{
    private Session $session;
    private ControleManager $controleManager;
    private ControleLigneManager $controleLigneManager;
    private EquipementManager $equipementManager;
    private UtilisateurManager $utilisateurManager;
    private CategorieManager $categorieManager;         // ← NOUVEAU
    private EmplacementManager $emplacementManager;     // ← NOUVEAU
    private ConfigProvider $configProvider;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session               = $this->mockSession();
        $this->controleManager       = $this->mockConcrete(ControleManager::class);
        $this->controleLigneManager  = $this->mockConcrete(ControleLigneManager::class);
        $this->equipementManager     = $this->mockConcrete(EquipementManager::class);
        $this->utilisateurManager    = $this->mockConcrete(UtilisateurManager::class);
        $this->categorieManager      = $this->mockConcrete(CategorieManager::class);       // ← NOUVEAU
        $this->emplacementManager    = $this->mockConcrete(EmplacementManager::class);     // ← NOUVEAU
        $this->configProvider        = $this->defaultConfigProvider();
    }

    public function withSession(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function withControleManager(ControleManager $manager): self
    {
        $this->controleManager = $manager;
        return $this;
    }

    public function withControleLigneManager(ControleLigneManager $manager): self
    {
        $this->controleLigneManager = $manager;
        return $this;
    }

    public function withEquipementManager(EquipementManager $manager): self
    {
        $this->equipementManager = $manager;
        return $this;
    }

    public function withUtilisateurManager(UtilisateurManager $manager): self
    {
        $this->utilisateurManager = $manager;
        return $this;
    }

    // ═══════════════════════════════════════════════════════════════
    // [VAGUE 11 — issue #61] Nouveaux setters
    // ═══════════════════════════════════════════════════════════════

    public function withCategorieManager(CategorieManager $manager): self
    {
        $this->categorieManager = $manager;
        return $this;
    }

    public function withEmplacementManager(EmplacementManager $manager): self
    {
        $this->emplacementManager = $manager;
        return $this;
    }

    // ═══════════════════════════════════════════════════════════════

    public function withConfigProvider(ConfigProvider $provider): self
    {
        $this->configProvider = $provider;
        return $this;
    }

    public function build(): TestableControleController
    {
        return new TestableControleController(
            $this->session,
            $this->controleManager,
            $this->controleLigneManager,
            $this->equipementManager,
            $this->utilisateurManager,
            $this->categorieManager,        // ← NOUVEAU
            $this->emplacementManager,      // ← NOUVEAU
            $this->configProvider,
        );
    }

    // ==================================================================
    // Helpers internes (inchangés)
    // ==================================================================

    private function defaultConfigProvider(): ConfigProvider
    {
        $fixture = dirname(__DIR__, 3) . '/Fixtures/env.test.php';
        return new ConfigProvider($fixture);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function mockConcrete(string $class): object
    {
        return (new MockBuilder($this->testCase, $class))
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function mockSession(): Session
    {
        $session = (new MockBuilder($this->testCase, Session::class))->getMock();
        $session->method('get')->willReturn(null);
        $session->method('isAuthenticated')->willReturn(true);
        $session->method('isGranted')->willReturn(true);
        return $session;
    }
}