<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AppUserAuthenticator;
use Epiclub\Engine\Session;

/**
 * [SÉCURITÉ — audit P34] Sous-classe de test d'AppUserAuthenticator :
 * injecte un UtilisateurManager adossé à un PDO mocké.
 */
final class TestableAppUserAuthenticator extends AppUserAuthenticator
{
    public function __construct(
        Session $session,
        private readonly UtilisateurManager $manager,
    ) {
        parent::__construct($session);
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return $this->manager;
    }
}
