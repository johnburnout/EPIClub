<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\ClubManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

final class TestableClubControllerBuilder
{
    private Session $session;
    private ClubManager $clubManager;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session     = $this->mockSession();
        $this->clubManager = $this->mockConcrete(ClubManager::class);
    }

    public function withSession(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function withClubManager(ClubManager $manager): self
    {
        $this->clubManager = $manager;
        return $this;
    }

    public function build(): TestableClubController
    {
        return new TestableClubController(
            $this->session,
            $this->clubManager,
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