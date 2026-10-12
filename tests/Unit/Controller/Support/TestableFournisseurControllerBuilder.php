<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

final class TestableFournisseurControllerBuilder
{
    private Session $session;
    private FournisseurManager $fournisseurManager;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session            = $this->mockSession();
        $this->fournisseurManager = $this->mockConcrete(FournisseurManager::class);
    }

    public function withSession(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function withFournisseurManager(FournisseurManager $manager): self
    {
        $this->fournisseurManager = $manager;
        return $this;
    }

    public function build(): TestableFournisseurController
    {
        return new TestableFournisseurController(
            $this->session,
            $this->fournisseurManager,
        );
    }

    // ==================================================================
    // Helpers internes
    // ==================================================================

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