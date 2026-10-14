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
    * [REFACTOR #78 - Dette 5] Vérifie que la factory encryptionService()
    * héritée d'AbstractController instancie bien le service avec un
    * ConfigProvider.
    *
    * Avant #78, la factory était déclarée dans JournalController
    * directement (fix #70). Depuis #78, elle est dans AbstractController.
    * Ce test conserve sa valeur de non-régression : un EncryptionService
    * doit être instanciable via JournalController.
    */
    public function testEncryptionServiceFactoryInstantiatesWithConfigProvider(): void
    {
        $session = $this->createMock(Session::class);
        $journalManager = $this->createMock(JournalManager::class);
        
        $controller = new TestableJournalController($session, $journalManager);
        
        // [REFACTOR #78] La méthode est désormais héritée d'AbstractController.
        // ReflectionMethod la résout toujours via JournalController::class.
        $reflection = new \ReflectionMethod(JournalController::class, 'encryptionService');
        
        $service = $reflection->invoke($controller);
        
    self::assertInstanceOf(EncryptionService::class, $service);
    }
}