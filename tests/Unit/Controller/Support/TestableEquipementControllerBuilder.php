<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR VAGUE 11] Builder pour TestableEquipementController.
 *
 * ⚠️ PHPUnit 11 : createMock() est protected → on utilise MockBuilder
 *    directement pour instancier les mocks depuis cette classe externe.
 *
 * ⚠️ La Session fournie par défaut NE CONTIENT PAS user['id'] :
 *    cela évite que AbstractController::updateLastActivity() n'ouvre
 *    une connexion PDO via UtilisateurManager.
 */
final class TestableEquipementControllerBuilder
{
    private Session $session;
    private EquipementManager $equipementManager;
    private CategorieManager $categorieManager;
    private EmplacementManager $emplacementManager;
    private AcquisitionManager $acquisitionManager;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session              = $this->mockSession();
        $this->equipementManager    = $this->mockConcrete(EquipementManager::class);
        $this->categorieManager     = $this->mockConcrete(CategorieManager::class);
        $this->emplacementManager   = $this->mockConcrete(EmplacementManager::class);
        $this->acquisitionManager   = $this->mockConcrete(AcquisitionManager::class);
    }

    public function withSession(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function withEquipementManager(EquipementManager $manager): self
    {
        $this->equipementManager = $manager;
        return $this;
    }

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

    public function withAcquisitionManager(AcquisitionManager $manager): self
    {
        $this->acquisitionManager = $manager;
        return $this;
    }

    public function build(): TestableEquipementController
    {
        return new TestableEquipementController(
            $this->session,
            $this->equipementManager,
            $this->categorieManager,
            $this->emplacementManager,
            $this->acquisitionManager,
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