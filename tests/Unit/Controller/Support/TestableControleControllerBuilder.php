<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR VAGUE 11] Builder pour TestableControleController.
 *
 * ⚠️ PHPUnit 11 : createMock() est protected → on utilise MockBuilder
 *    directement pour instancier les mocks depuis cette classe externe.
 *
 * ⚠️ La Session fournie par défaut NE CONTIENT PAS user['id'] :
 *    cela évite que AbstractController::updateLastActivity() n'ouvre
 *    une connexion PDO via UtilisateurManager.
 */
final class TestableControleControllerBuilder
{
    private Session $session;
    private ControleManager $controleManager;
    private ControleLigneManager $controleLigneManager;
    private EquipementManager $equipementManager;
    private UtilisateurManager $utilisateurManager;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session               = $this->mockSession();
        $this->controleManager       = $this->mockConcrete(ControleManager::class);
        $this->controleLigneManager  = $this->mockConcrete(ControleLigneManager::class);
        $this->equipementManager     = $this->mockConcrete(EquipementManager::class);
        $this->utilisateurManager    = $this->mockConcrete(UtilisateurManager::class);
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

    public function build(): TestableControleController
    {
        return new TestableControleController(
            $this->session,
            $this->controleManager,
            $this->controleLigneManager,
            $this->equipementManager,
            $this->utilisateurManager,
        );
    }

    // ==================================================================
    // Helpers internes — utilisent MockBuilder (public en PHPUnit 11)
    // ==================================================================

    /**
     * Mock une classe concrète en désactivant le constructeur
     * (évite AbstractManager → PDO).
     *
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