<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\TicketController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\TicketManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;

/**
 * [2026-10-24] Sous-classe de test de TicketController : managers et
 * mailer mockés, date figée, URL de base fixe.
 */
final class TestableTicketController extends TicketController
{
    public function __construct(
        Session $session,
        private readonly TicketManager $tickets,
        private readonly EquipementManager $equipements,
        private readonly UtilisateurManager $utilisateurs,
        private readonly ClubManager $club,
        private readonly MailerServiceInterface $mailer,
    ) {
        parent::__construct($session);
    }

    protected function ticketManager(): TicketManager
    {
        return $this->tickets;
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipements;
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return $this->utilisateurs;
    }

    protected function clubManager(): ClubManager
    {
        return $this->club;
    }

    protected function mailerService(): MailerServiceInterface
    {
        return $this->mailer;
    }

    protected function maintenant(): string
    {
        return '2026-10-24 12:00:00';
    }

    protected function getBaseUrl(): string
    {
        return 'https://club.test';
    }

    protected function updateLastActivity(): void
    {
        // no-op : pas de PDO en test Unit
    }
}
