<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain\Support;

use Epiclub\Domain\LoginThrottleManager;

/**
 * [audit 2026-10-19 S2] LoginThrottleManager sans MySQL : stockage en
 * mémoire et horloge pilotable ($horloge), pour tester la politique
 * de blocage (et simuler l'écoulement du temps) en Unit.
 */
final class InMemoryLoginThrottleManager extends LoginThrottleManager
{
    /** @var array<string, array{echecs:int,dernier_echec:int,bloque_jusqu_a:?int}> */
    public array $lignes = [];
    public int $horloge = 1_800_000_000;

    public function __construct()
    {
        // Ne PAS appeler parent::__construct() : il ouvrirait une connexion MySQL.
    }

    protected function lire(string $cleHachee): ?array
    {
        return $this->lignes[$cleHachee] ?? null;
    }

    protected function ecrire(string $cleHachee, int $echecs, int $dernierEchec, ?int $bloqueJusquA): void
    {
        $this->lignes[$cleHachee] = [
            'echecs' => $echecs, 'dernier_echec' => $dernierEchec, 'bloque_jusqu_a' => $bloqueJusquA,
        ];
    }

    protected function supprimer(string $cleHachee): void
    {
        unset($this->lignes[$cleHachee]);
    }

    protected function purger(int $avant): void
    {
        $this->lignes = array_filter($this->lignes, fn (array $l) => $l['dernier_echec'] >= $avant);
    }

    protected function maintenant(): int
    {
        return $this->horloge;
    }
}
