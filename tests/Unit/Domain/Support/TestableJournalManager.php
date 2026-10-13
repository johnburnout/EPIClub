<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain\Support;

use Epiclub\Domain\JournalManager;

/**
 * Sous-classe de test pour JournalManager :
 *  - court-circuite AbstractManager::__construct() (pas de PDO réel),
 *  - expose buildOrderByClause() en public,
 *  - permet d'injecter un PDO mocké dans $this->db.
 *
 * ⚠️ La propriété est nommée $mockPdo et non $pdo : si elle s'appelait
 * $pdo, property_exists($this, 'pdo') renverrait true dans getPdo()
 * (hérité), faussant les tests tant que la méthode n'est pas supprimée.
 */
final class TestableJournalManager extends JournalManager
{
    public function __construct(private \PDO $mockPdo)
    {
    }

    public function exposeBuildOrderByClause(?string $orderBy, ?string $orderDir): string
    {
        return $this->buildOrderByClause($orderBy, $orderDir);
    }

    public function injectDb(): void
    {
        $this->db = $this->mockPdo;
    }
}