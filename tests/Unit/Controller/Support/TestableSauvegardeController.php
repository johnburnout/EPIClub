<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\SauvegardeController;
use Epiclub\Engine\SauvegardeService;
use Epiclub\Engine\Session;

/**
 * Sous-classe de test de SauvegardeController : service injecté (base
 * simulée, dossiers temporaires), pas de PDO ni de migrations réelles.
 */
final class TestableSauvegardeController extends SauvegardeController
{
    public int $migrations = 0;

    public function __construct(Session $session, private SauvegardeService $service)
    {
        parent::__construct($session);
    }

    protected function updateLastActivity(): void
    {
        // no-op : évite new UtilisateurManager() → PDO
    }

    protected function sauvegardeService(): SauvegardeService
    {
        return $this->service;
    }

    protected function migrerBase(): array
    {
        $this->migrations++;
        return [];
    }

    protected function prolongerExecution(): void
    {
    }
}
