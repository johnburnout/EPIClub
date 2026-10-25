<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\Pagination;
use Epiclub\Engine\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-25] Pagination des listes : affichée au-delà de 10 éléments,
 * cachée à 10 ou moins (tout tient alors sur une page).
 */
final class PaginationTest extends TestCase
{
    public function testDixElementsOuMoinsUnePageSansPagination(): void
    {
        foreach ([0, 1, 10] as $n) {
            [$elements, $p] = Pagination::paginer($n === 0 ? [] : range(1, $n), Request::create('/?page=3&limit=1'));
            self::assertCount($n, $elements, "$n éléments");
            self::assertFalse($p['visible'], "$n éléments");
            self::assertSame(1, $p['totalPages']);
        }
    }

    public function testAuDelaDeDixPaginationAffichee(): void
    {
        [$elements, $p] = Pagination::paginer(range(1, 23), Request::create('/?page=3&q=casque'));

        self::assertTrue($p['visible']);
        self::assertSame([21, 22, 23], $elements);
        self::assertSame([3, 3, 10], [$p['page'], $p['totalPages'], $p['limit']]);
        self::assertSame(['q' => 'casque'], $p['query']);
        self::assertSame('?q=casque&limit=10&page=2', $p['urls']['previous']);
        self::assertSame('?q=casque&limit=10&page=1', $p['urls']['first']);
    }

    public function testLimiteEtPageBornees(): void
    {
        self::assertSame(25, Pagination::limite('25'));
        foreach (['5', '1', '1000', 'abc', null] as $valeur) {
            self::assertSame(10, Pagination::limite($valeur));
        }
        [, $p] = Pagination::paginer(range(1, 30), Request::create('/?page=99'));
        self::assertSame(3, $p['page']);
    }

    public function testPrefixeEtAncre(): void
    {
        [$elements, $p] = Pagination::paginer(range(1, 12), Request::create('/?lignes_page=2&page=4'), 'lignes_', '#retour');

        self::assertSame([11, 12], $elements);
        self::assertSame('?page=4&lignes_limit=10&lignes_page=1#retour', $p['urls']['first']);
    }

    public function testGabarit(): void
    {
        $twig = new TwigRenderer(new \Epiclub\Engine\Session(new MockArraySessionStorage()));
        $rendu = fn (int $n) => $twig->render('_pagination.twig', [
            'p' => Pagination::paginer(range(1, $n), Request::create('/?q=x'))[1], 'affiches' => min($n, 10), 'elements' => 'éléments',
        ]);

        self::assertSame('', trim($rendu(10)));
        $html = $rendu(11);
        self::assertStringContainsString('Page 1 sur 2', $html);
        self::assertStringContainsString('<input type="hidden" name="q" value="x">', $html);
        self::assertStringContainsString('Affichage de 10 éléments sur 11', $html);
    }
}
