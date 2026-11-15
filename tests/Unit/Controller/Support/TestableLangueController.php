<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\LangueController;

final class TestableLangueController extends LangueController
{
    /** @var list<array{0:int,1:string}> */
    public array $enregistrements = [];
    public bool $echec = false;

    protected function enregistrerPreference(int $utilisateurId, string $langue): void
    {
        if ($this->echec) {
            throw new \PDOException("Unknown column 'langue'");
        }
        $this->enregistrements[] = [$utilisateurId, $langue];
    }

    protected function updateLastActivity(): void
    {
        // no-op (évite tout accès BDD en test Unit)
    }
}
