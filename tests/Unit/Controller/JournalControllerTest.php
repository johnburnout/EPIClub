<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\JournalController;
use Epiclub\Domain\JournalManager;
use Epiclub\Engine\EncryptionService;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableJournalController;
use PHPUnit\Framework\TestCase;

final class JournalControllerTest extends TestCase
{
    /**
     * Test rouge avant fix #70 :
     * JournalController::__construct() faisait new EncryptionService()
     * sans argument → ArgumentCountError.
     * Après fix : la factory encryptionService() injecte le ConfigProvider.
     */
    public function testConstructDoesNotThrow(): void
    {
        $session = $this->createMock(Session::class);
        $journalManager = $this->createMock(JournalManager::class);

        $controller = new TestableJournalController($session, $journalManager);

        self::assertInstanceOf(JournalController::class, $controller);
    }

    /**
     * Vérifie que la factory encryptionService() instancie bien le
     * service avec un ConfigProvider (plus d'ArgumentCountError).
     */
    public function testEncryptionServiceFactoryInstantiatesWithConfigProvider(): void
    {
        $session = $this->createMock(Session::class);
        $journalManager = $this->createMock(JournalManager::class);

        $controller = new TestableJournalController($session, $journalManager);

        // On utilise la réflexion pour appeler la factory PARENT
        // (contourne la surcharge du testable).
        $reflection = new \ReflectionMethod(JournalController::class, 'encryptionService');

        $service = $reflection->invoke($controller);

        self::assertInstanceOf(EncryptionService::class, $service);
    }
}