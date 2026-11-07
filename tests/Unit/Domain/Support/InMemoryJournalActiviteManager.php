<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain\Support;

use Epiclub\Domain\JournalActiviteManager;

/**
 * Journal d'activité en mémoire : entrées inscrites par les contrôleurs
 * pendant un test (type, action, IP), sans base.
 */
final class InMemoryJournalActiviteManager extends JournalActiviteManager
{
    /** @var list<array{type: string, action: string, ip: ?string}> */
    public array $entrees = [];

    public function __construct()
    {
        // Pas de parent::__construct() : aucune connexion à la base.
    }

    public function enregistrer(string $type, string $action, ?array $utilisateur = null, ?string $ip = null): void
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException(sprintf('Type de journal inconnu : "%s"', $type));
        }
        $this->entrees[] = ['type' => $type, 'action' => $action, 'ip' => $ip];
    }

    /** @return list<string> actions inscrites pour ce type */
    public function actions(string $type): array
    {
        return array_values(array_map(
            static fn (array $e) => $e['action'],
            array_filter($this->entrees, static fn (array $e) => $e['type'] === $type)
        ));
    }
}
