<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\UtilisateurController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;

final class TestableUtilisateurController extends UtilisateurController
{
    public function __construct(
        Session $session,
        private readonly UtilisateurManager $utilisateurManager,
    ) {
        parent::__construct($session);
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return $this->utilisateurManager;
    }

    /** [2026-10-24] Doublures du mail de création ; sans elles, pas d'envoi (pas de PDO). */
    public ?ClubManager $clubManagerOverride = null;
    public ?MailerServiceInterface $mailerOverride = null;

    protected function clubManager(): ClubManager
    {
        return $this->clubManagerOverride ?? throw new \RuntimeException('Pas de club en test.');
    }

    protected function mailerService(): MailerServiceInterface
    {
        return $this->mailerOverride ?? throw new \RuntimeException('Pas de mailer en test.');
    }

    protected function getBaseUrl(): string
    {
        return 'https://club.test';
    }

    /**
     * [audit 2026-10-19 S5/Q2] Pas de synchronisation de session en test
     * Unit : l'implémentation parente ouvre une vraie connexion MySQL.
     */
    protected function updateLastActivity()
    {
    }
}
