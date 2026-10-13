<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\JournalController;
use Epiclub\Domain\JournalManager;
use Epiclub\Engine\EncryptionService;
use Epiclub\Engine\Session;

/**
 * Sous-classe de test pour JournalController :
 *  - appelle le VRAI constructeur parent (teste le constructeur réel),
 *  - surcharge updateLastActivity() en no-op (évite new UtilisateurManager() → PDO),
 *  - permet d'injecter des mocks JournalManager / EncryptionService
 *    via surcharge des factories.
 */
final class TestableJournalController extends JournalController
{
    public function __construct(
        Session $session,
        private ?JournalManager $injectedJournalManager = null,
        private ?EncryptionService $injectedEncryptionService = null,
    ) {
        parent::__construct($session);
    }

    protected function updateLastActivity(): void
    {
        // no-op : évite new UtilisateurManager() → PDO
    }

    protected function journalManager(): JournalManager
    {
        return $this->injectedJournalManager ?? parent::journalManager();
    }

    protected function encryptionService(): EncryptionService
    {
        return $this->injectedEncryptionService ?? parent::encryptionService();
    }
}