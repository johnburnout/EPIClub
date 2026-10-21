<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\SuppressionEgare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [migration 017] Suppression d'un équipement égaré après un délai.
 */
final class SuppressionEgareTest extends TestCase
{
    /** @return array<string, array{mixed, int}> */
    public static function delais(): array
    {
        return [
            'défaut si absent' => [null, 12],
            'entier'           => [6, 6],
            'chaîne'           => ['24', 24],
            'zéro'             => [0, 12],
            'trop grand'       => [121, 12],
            'texte'            => ['un an', 12],
            'maximum'          => ['120', 120],
        ];
    }

    #[DataProvider('delais')]
    public function testNormaliserDelai(mixed $valeur, int $attendu): void
    {
        self::assertSame($attendu, SuppressionEgare::normaliserDelai($valeur));
    }

    public function testDateLimite(): void
    {
        self::assertSame('2025-10-21', SuppressionEgare::dateLimite('2026-10-21', 12));
        self::assertSame('2026-04-21', SuppressionEgare::dateLimite('2026-10-21', 6));
    }

    public function testAutorisee(): void
    {
        $egare = ['statut' => 4, 'controle_en_cours' => 0, 'date_egare' => '2025-10-21'];

        self::assertTrue(SuppressionEgare::autorisee($egare, '2025-10-21'));
        self::assertFalse(SuppressionEgare::autorisee($egare, '2025-10-20'), 'délai non écoulé');
        self::assertFalse(SuppressionEgare::autorisee(['statut' => 2] + $egare, '2026-10-21'), 'pas égaré');
        self::assertFalse(SuppressionEgare::autorisee(['controle_en_cours' => 1] + $egare, '2026-10-21'), 'contrôle en cours');
        self::assertFalse(SuppressionEgare::autorisee(['date_egare' => null] + $egare, '2026-10-21'), 'sans date');
    }

    public function testDateSuppressionPossible(): void
    {
        self::assertSame('2027-10-21', SuppressionEgare::dateSuppressionPossible(['statut' => 4, 'date_egare' => '2026-10-21'], 12));
        self::assertNull(SuppressionEgare::dateSuppressionPossible(['statut' => 0, 'date_egare' => '2026-10-21'], 12));
        self::assertNull(SuppressionEgare::dateSuppressionPossible(['statut' => 4], 12));
    }
}
