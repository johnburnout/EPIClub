<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AppUserRegisterController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;

final class TestableAppUserRegisterController extends AppUserRegisterController
{
    public function __construct(
        Session $session,
        private readonly UtilisateurManager $utilisateurManager,
        private readonly ClubManager $clubManager,
        private readonly MailerServiceInterface $mailerService,
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

    protected function mailerService(): MailerServiceInterface
    {
        return $this->mailerService;
    }
}