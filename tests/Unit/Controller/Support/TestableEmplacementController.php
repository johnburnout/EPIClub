<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\EmplacementController;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Request;

final class TestableEmplacementController extends EmplacementController
{
    public function __construct(
        Session $session,
        private readonly EmplacementManager $emplacementManager,
    ) {
        parent::__construct($session);
    }

    protected function emplacementManager(): EmplacementManager
    {
        return $this->emplacementManager;
    }

    /** [2026-10-25] Doublure du comptage des équipements ; sans elle, pas de comptage (pas de PDO). */
    public ?EquipementManager $equipementManagerOverride = null;

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManagerOverride ?? throw new \RuntimeException('Pas d\'équipements en test.');
    }

    protected function updateLastActivity(): void
    {
        // no-op — évite new UtilisateurManager() qui ouvre PDO.
    }

    protected function validateCsrf(Request $request): void
    {
        // no-op — CSRF est testé isolément dans CsrfValidatorTest.
        // Ici on teste la validation métier (libelle, URL image).
    }
}