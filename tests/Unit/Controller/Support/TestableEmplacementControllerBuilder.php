<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\EmplacementManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

final class TestableEmplacementControllerBuilder
{
    private Session $session;
    private EmplacementManager $emplacementManager;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session            = $this->mockSession();
        $this->emplacementManager = $this->mockConcrete(EmplacementManager::class);
    }

    public function withSession(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function withEmplacementManager(EmplacementManager $manager): self
    {
        $this->emplacementManager = $manager;
        return $this;
    }

    public function build(): TestableEmplacementController
    {
        return new TestableEmplacementController(
            $this->session,
            $this->emplacementManager,
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