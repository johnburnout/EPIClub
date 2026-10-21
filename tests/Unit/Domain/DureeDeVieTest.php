<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\DureeDeVie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [MÉTIER 2026-10-21] Date limite d'utilisation par défaut :
 * date d'achat + 10 ans, dernier jour du mois.
 */
final class DureeDeVieTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function dates(): array
    {
        return [
            'milieu de mois'             => ['2026-03-15', '2036-03-31'],
            'premier du mois'            => ['2019-01-01', '2029-01-31'],
            'dernier du mois'            => ['2026-04-30', '2036-04-30'],
            'février non bissextile'     => ['2025-02-10', '2035-02-28'],
            '29 février → fin février'   => ['2024-02-29', '2034-02-28'],
            'février bissextile à +10'   => ['2018-02-05', '2028-02-29'],
            'décembre'                   => ['2026-12-31', '2036-12-31'],
            'date et heure (facture)'    => ['2026-10-21 14:05:00', '2036-10-31'],
            'date invalide'              => ['2026-13-01', null],
            'vide'                       => ['', null],
            'année seule'                => ['2019', null],
        ];
    }

    #[DataProvider('dates')]
    public function testFinDeVie(string $achat, ?string $attendu): void
    {
        self::assertSame($attendu, DureeDeVie::finDeVie($achat));
    }
}
