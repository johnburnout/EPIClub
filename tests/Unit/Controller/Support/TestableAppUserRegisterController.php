<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AppUserRegisterController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\LoginThrottleManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Domain\Support\InMemoryLoginThrottleManager;

final class TestableAppUserRegisterController extends AppUserRegisterController
{
    public function __construct(
        Session $session,
        private readonly UtilisateurManager $utilisateurManager,
        private readonly ClubManager $clubManager,
        private readonly MailerServiceInterface $mailerService,
        private ?LoginThrottleManager $limiteur = null,
    ) {
        parent::__construct($session);
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return $this->utilisateurManager;
    }

    protected function clubManager(): ClubManager
    {
        return $this->clubManager;
    }

    /** [audit 2026-10-19 S2] Limiteur en mémoire par défaut (pas de MySQL). */
    protected function loginThrottle(): LoginThrottleManager
    {
        return $this->limiteur ??= new InMemoryLoginThrottleManager();
    }

    protected function mailerService(): MailerServiceInterface
    {
        return $this->mailerService;
    }
}