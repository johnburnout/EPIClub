<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AppUserAuthController;
use Epiclub\Engine\AppUserAuthenticator;
use Epiclub\Engine\Session;

/**
 * [#102] Sous-classe de test d'AppUserAuthController.
 *
 * Injecte un AppUserAuthenticator mocké via la factory authenticator()
 * (pattern Vague 11) — login() n'ouvre plus de connexion PDO en test.
 */
final class TestableAppUserAuthController extends AppUserAuthController
{
    public function __construct(
        Session $session,
        private ?AppUserAuthenticator $authenticatorMock = null,
    ) {
        parent::__construct($session);
    }

    protected function updateLastActivity(): void
    {
        // no-op : évite new UtilisateurManager() → PDO
    }

    protected function authenticator(): AppUserAuthenticator
    {
        return $this->authenticatorMock ?? parent::authenticator();
    }
}
