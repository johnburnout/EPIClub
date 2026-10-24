<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\TriAlphabetique;
use Epiclub\Engine\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-24] Listes déroulantes par ordre alphabétique (filtre Twig
 * « tri_alpha ») : ordre français, sans majuscules ni accents, nombres
 * dans l'ordre naturel.
 */
final class TriAlphabetiqueTest extends TestCase
{
    private const DESORDRE = ['Zinc', 'Église', 'Armoire 10', 'échelle', 'armoire 2', 'Baudrier'];
    private const ATTENDU = ['armoire 2', 'Armoire 10', 'Baudrier', 'échelle', 'Église', 'Zinc'];

    public function testListeDeTextes(): void
    {
        self::assertSame(self::ATTENDU, TriAlphabetique::trier(self::DESORDRE));
    }

    public function testSansExtensionIntl(): void
    {
        // Repli : textes normalisés, comparaison naturelle.
        $liste = self::DESORDRE;
        usort($liste, static fn ($a, $b) => strnatcmp(TriAlphabetique::normaliser($a), TriAlphabetique::normaliser($b)));

        self::assertSame(self::ATTENDU, $liste);
        self::assertSame('echelle oeuvre', TriAlphabetique::normaliser('Échelle Œuvre'));
    }

    public function testParChampEtParPlusieursChamps(): void
    {
        $categories = [['id' => 1, 'libelle' => 'Longes'], ['id' => 2, 'libelle' => 'Casques'], ['id' => 3, 'libelle' => 'Échelles']];
        self::assertSame([2, 3, 1], array_column(TriAlphabetique::trier($categories, 'libelle'), 'id'));

        $controleurs = [['id' => 1, 'prenom' => 'Paul', 'nom' => 'Martin'], ['id' => 2, 'prenom' => 'Anne', 'nom' => 'Zola'],
                        ['id' => 3, 'prenom' => 'Paul', 'nom' => 'Andre']];
        self::assertSame([2, 3, 1], array_column(TriAlphabetique::trier($controleurs, ['prenom', 'nom']), 'id'));
    }

    public function testListeAbsente(): void
    {
        self::assertSame([], TriAlphabetique::trier(null));
        self::assertSame([], TriAlphabetique::trier(false));
        self::assertSame(['a', 'b'], TriAlphabetique::trier(new \ArrayIterator(['b', 'a'])));
    }

    public function testFiltreTwig(): void
    {
        $twig = new TwigRenderer(new \Epiclub\Engine\Session(new MockArraySessionStorage()));
        $html = $twig->createTemplate("{% for c in categories|tri_alpha('libelle') %}{{ c.libelle }};{% endfor %}")
            ->render(['categories' => [['libelle' => 'Longes'], ['libelle' => 'casques'], ['libelle' => 'Échelles']]]);

        self::assertSame('casques;Échelles;Longes;', $html);
    }
}
