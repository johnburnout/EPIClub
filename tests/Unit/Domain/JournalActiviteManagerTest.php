<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\JournalActiviteManager;
use PHPUnit\Framework\TestCase;

/**
 * [migration 019] Parties pures du journal d'activité.
 */
final class JournalActiviteManagerTest extends TestCase
{
    public function testSansFiltreAucuneClause(): void
    {
        self::assertSame(['', []], JournalActiviteManager::clauseWhere([]));
    }

    public function testTousLesFiltres(): void
    {
        [$where, $params] = JournalActiviteManager::clauseWhere([
            'type' => 'controle', 'du' => '2026-10-01', 'au' => '2026-10-31', 'q' => '50%_off',
        ]);

        self::assertSame(
            ' WHERE type = :type AND date_action >= :du AND date_action <= :au AND (utilisateur LIKE :q1 OR action LIKE :q2)',
            $where
        );
        self::assertSame([
            'type' => 'controle',
            'du' => '2026-10-01 00:00:00',
            'au' => '2026-10-31 23:59:59',
            'q1' => '%50\%\_off%',
            'q2' => '%50\%\_off%',
        ], $params);
    }

    public function testTypeInconnuIgnore(): void
    {
        self::assertSame(['', []], JournalActiviteManager::clauseWhere(['type' => "x' OR 1=1"]));
    }

    public function testNomAffiche(): void
    {
        self::assertSame('Ada Lovelace (ada)', JournalActiviteManager::nomAffiche(['prenom' => 'Ada', 'nom' => 'Lovelace', 'username' => 'ada']));
        self::assertSame('ada', JournalActiviteManager::nomAffiche(['username' => 'ada']));
        self::assertSame('', JournalActiviteManager::nomAffiche(null));
    }
}
