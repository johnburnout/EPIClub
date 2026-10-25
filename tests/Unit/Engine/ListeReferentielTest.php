<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\ListeReferentiel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * [2026-10-25] Recherche, filtres et tri des listes de référentiels.
 */
final class ListeReferentielTest extends TestCase
{
    private const LIGNES = [
        ['id' => 1, 'libelle' => 'Échelles', 'code' => 'ECH', 'description' => 'Matériel de spéléo'],
        ['id' => 2, 'libelle' => 'Casques', 'code' => 'CAS', 'description' => ''],
        ['id' => 3, 'libelle' => 'cordes', 'code' => 'COR', 'description' => 'Dynamiques'],
    ];
    private const NB = [2 => 5, 3 => 1];

    public function testParametresValides(): void
    {
        $p = ListeReferentiel::parametres(
            Request::create('/', 'GET', ['q' => '  corde ', 'order_by' => 'code', 'order_dir' => 'desc', 'equipements' => 'avec', 'epi' => 'oui']),
            ['libelle', 'code'], 'libelle', ['epi' => ['oui', 'non']]
        );

        self::assertSame(['q' => 'corde', 'order_by' => 'code', 'order_dir' => 'desc', 'equipements' => 'avec', 'epi' => 'oui'], $p);
    }

    public function testParametresInvalidesIgnores(): void
    {
        $p = ListeReferentiel::parametres(
            Request::create('/', 'GET', ['q' => '', 'order_by' => 'password', 'order_dir' => 'x', 'equipements' => 'tous', 'epi' => '1']),
            ['libelle'], 'libelle', ['epi' => ['oui', 'non']]
        );

        self::assertSame(['q' => null, 'order_by' => 'libelle', 'order_dir' => 'asc', 'equipements' => null, 'epi' => null], $p);
    }

    public function testRechercheSansAccentsNiMajuscules(): void
    {
        $ids = fn (array $l) => array_column($l, 'id');

        self::assertSame([1], $ids(ListeReferentiel::rechercher(self::LIGNES, 'ECHEL', ['libelle', 'code', 'description'])));
        self::assertSame([1], $ids(ListeReferentiel::rechercher(self::LIGNES, 'speleo', ['libelle', 'description'])));
        self::assertSame([1, 2, 3], $ids(ListeReferentiel::rechercher(self::LIGNES, null, ['libelle'])));
        self::assertSame([], ListeReferentiel::rechercher(self::LIGNES, 'zzz', ['libelle']));
    }

    public function testFiltreEquipements(): void
    {
        self::assertSame([2, 3], array_column(ListeReferentiel::filtrerEquipements(self::LIGNES, 'avec', self::NB), 'id'));
        self::assertSame([1], array_column(ListeReferentiel::filtrerEquipements(self::LIGNES, 'sans', self::NB), 'id'));
    }

    public function testTri(): void
    {
        $cles = ['libelle' => 'libelle', 'code' => 'code', 'equipements' => fn (array $l) => self::NB[$l['id']] ?? 0];
        $trier = fn (string $par, string $sens) => array_column(ListeReferentiel::trier(self::LIGNES, $par, $sens, $cles, 'libelle'), 'id');

        self::assertSame([2, 3, 1], $trier('libelle', 'asc'));     // Casques, cordes, Échelles
        self::assertSame([1, 3, 2], $trier('libelle', 'desc'));
        self::assertSame([2, 3, 1], $trier('code', 'asc'));        // CAS, COR, ECH
        self::assertSame([1, 3, 2], $trier('equipements', 'asc')); // 0, 1, 5
        self::assertSame([2, 3, 1], $trier('equipements', 'desc'));
    }
}
