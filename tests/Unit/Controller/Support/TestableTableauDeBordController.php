<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\TableauDeBordController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\TicketManager;
use Epiclub\Engine\Session;

/**
 * Sous-classe de test de TableauDeBordController (pattern Vague 11) :
 * managers mockés, dossier d'images temporaire, date du jour figée.
 */
final class TestableTableauDeBordController extends TableauDeBordController
{
    public function __construct(
        Session $session,
        private readonly ClubManager $clubManagerMock,
        private readonly EquipementManager $equipementManagerMock,
        private readonly string $imagesDirOverride,
        private readonly \DateTimeImmutable $aujourdhuiFige,
    ) {
        parent::__construct($session);
    }

    protected function clubManager(): ClubManager
    {
        return $this->clubManagerMock;
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManagerMock;
    }

    /** [2026-10-24] Doublure des tickets ; sans elle, aucun ticket (pas de PDO). */
    public ?TicketManager $ticketManagerOverride = null;

    protected function ticketManager(): TicketManager
    {
        return $this->ticketManagerOverride ?? throw new \RuntimeException('Pas de tickets en test.');
    }

    protected function imagesDir(): string
    {
        return $this->imagesDirOverride;
    }

    protected function aujourdhui(): \DateTimeImmutable
    {
        return $this->aujourdhuiFige;
    }

    protected function updateLastActivity(): void
    {
        // no-op : évite new UtilisateurManager() → PDO
    }
}
