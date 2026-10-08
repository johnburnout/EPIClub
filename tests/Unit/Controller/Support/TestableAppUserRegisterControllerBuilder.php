<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR VAGUE 11] Builder pour TestableAppUserRegisterController.
 *
 * ⚠️ PHPUnit 11 : createMock() est protected → on utilise MockBuilder
 *    directement pour instancier les mocks depuis cette classe externe.
 *
 * ⚠️ La Session fournie par défaut NE CONTIENT PAS user['id'] :
 *    cela évite que AbstractController::updateLastActivity() n'ouvre
 *    une connexion PDO via UtilisateurManager.
 */
final class TestableAppUserRegisterControllerBuilder
{
    private Session $session;
    private UtilisateurManager $utilisateurManager;
    private ClubManager $clubManager;
    private MailerServiceInterface $mailerService;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session            = $this->mockSession();
        $this->utilisateurManager = $this->mockConcrete(UtilisateurManager::class);
        $this->clubManager        = $this->mockConcrete(ClubManager::class);
        $this->mailerService      = $this->mockInterface(MailerServiceInterface::class);
    }

    public function withSession(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function withUtilisateurManager(UtilisateurManager $manager): self
    {
        $this->utilisateurManager = $manager;
        return $this;
    }

    public function withClubManager(ClubManager $manager): self
    {
        $this->clubManager = $manager;
        return $this;
    }

    public function withMailerService(MailerServiceInterface $service): self
    {
        $this->mailerService = $service;
        return $this;
    }

    public function build(): TestableAppUserRegisterController
    {
        return new TestableAppUserRegisterController(
            $this->session,
            $this->utilisateurManager,
            $this->clubManager,
            $this->mailerService,
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

    /**
     * Mock une interface (pas de constructeur).
     *
     * @template T of object
     * @param class-string<T> $interface
     * @return T
     */
    private function mockInterface(string $interface): object
    {
        return (new MockBuilder($this->testCase, $interface))->getMock();
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