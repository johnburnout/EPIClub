<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

final class TestableUtilisateurControllerBuilder
{
    private Session $session;
    private UtilisateurManager $utilisateurManager;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session            = $this->mockSession();
        $this->utilisateurManager = $this->mockConcrete(UtilisateurManager::class);
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

    public function build(): TestableUtilisateurController
    {
        return new TestableUtilisateurController(
            $this->session,
            $this->utilisateurManager,
        );
    }

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