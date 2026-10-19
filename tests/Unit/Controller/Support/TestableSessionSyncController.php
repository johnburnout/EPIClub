<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\Session;

/**
 * [audit 2026-10-19 S5] Contrôleur minimal pour tester la synchronisation
 * de l'utilisateur de session (AbstractController::updateLastActivity()),
 * avec un UtilisateurManager mocké.
 */
final class TestableSessionSyncController extends AbstractController
{
    private static ?UtilisateurManager $managerPourConstruction = null;

    public static function construire(Session $session, UtilisateurManager $manager): self
    {
        // La synchronisation a lieu dans le constructeur parent : le mock
        // doit être disponible avant parent::__construct().
        self::$managerPourConstruction = $manager;
        try {
            return new self($session);
        } finally {
            self::$managerPourConstruction = null;
        }
    }

    protected function sessionUtilisateurManager(): UtilisateurManager
    {
        return self::$managerPourConstruction
            ?? throw new \LogicException('Utiliser TestableSessionSyncController::construire()');
    }
}
