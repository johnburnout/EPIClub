<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR VAGUE 11] Builder pour TestableCategorieController.
 *
 * ⚠️ PHPUnit 11 : createMock() est protected → on utilise MockBuilder
 *    directement pour instancier les mocks depuis cette classe externe.
 *
 * ⚠️ La Session fournie par défaut NE CONTIENT PAS user['id'] :
 *    cela évite que AbstractController::updateLastActivity() n'ouvre
 *    une connexion PDO via UtilisateurManager.
 */
final class TestableCategorieControllerBuilder
{
    private Session $session;
    private CategorieManager $categorieManager;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session          = $this->mockSession();
        $this->categorieManager = $this->mockConcrete(CategorieManager::class);
    }

    public function withSession(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function withCategorieManager(CategorieManager $manager): self
    {
        $this->categorieManager = $manager;
        return $this;
    }

    public function build(): TestableCategorieController
    {
        return new TestableCategorieController(
            $this->session,
            $this->categorieManager,
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