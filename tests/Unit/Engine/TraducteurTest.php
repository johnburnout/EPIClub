<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\Langue;
use Epiclub\Engine\Traducteur;
use PHPUnit\Framework\TestCase;

/**
 * [Localisation] Traducteur : les clés sont le texte français. Une
 * chaîne absente d'un catalogue reste affichée en français (jamais de
 * clé technique ni de texte vide à l'écran).
 */
final class TraducteurTest extends TestCase
{
    protected function tearDown(): void
    {
        Traducteur::definirLangue(Langue::DEFAUT);
    }

    public function testFrancaisParDefautRenvoieLeTexte(): void
    {
        self::assertSame('fr', Traducteur::langue());
        self::assertSame('Tableau de bord', Traducteur::t('Tableau de bord'));
    }

    public function testTraduitDansLaLangueChoisie(): void
    {
        Traducteur::definirLangue('en');
        self::assertSame('en', Traducteur::langue());
        self::assertSame('Dashboard', Traducteur::t('Tableau de bord'));

        Traducteur::definirLangue('es');
        self::assertSame('Panel de control', Traducteur::t('Tableau de bord'));

        Traducteur::definirLangue('it');
        self::assertSame('Pannello di controllo', Traducteur::t('Tableau de bord'));
    }

    public function testTexteAbsentDuCatalogueResteEnFrancais(): void
    {
        Traducteur::definirLangue('en');
        self::assertSame('Texte jamais traduit', Traducteur::t('Texte jamais traduit'));
    }

    public function testLangueInconnueRetombeSurLeFrancais(): void
    {
        Traducteur::definirLangue('de');
        self::assertSame('fr', Traducteur::langue());

        Traducteur::definirLangue('../../etc/passwd');
        self::assertSame('fr', Traducteur::langue());
    }

    public function testParametresRemplaces(): void
    {
        self::assertSame(
            'Bonjour Ana, 3 messages',
            Traducteur::t('Bonjour {nom}, {n} messages', ['nom' => 'Ana', 'n' => 3])
        );
    }

    public function testParametresRemplacesApresTraduction(): void
    {
        Traducteur::definirLangue('en');
        // Clé présente au catalogue anglais avec ses paramètres.
        self::assertStringContainsString('3', Traducteur::t('Panne constatée depuis le {debut}', ['debut' => '3']));
        self::assertStringNotContainsString('{debut}', Traducteur::t('Panne constatée depuis le {debut}', ['debut' => '3']));
    }

    public function testEnFrancaisPuisRetourALaLangue(): void
    {
        Traducteur::definirLangue('es');
        self::assertSame('Panel de control', Traducteur::t('Tableau de bord'));
        self::assertSame('Tableau de bord', Traducteur::enFrancais(fn () => Traducteur::t('Tableau de bord')));
        self::assertSame('es', Traducteur::langue());
    }

    public function testEnFrancaisRetablitLaLangueApresUneException(): void
    {
        Traducteur::definirLangue('it');
        try {
            Traducteur::enFrancais(function (): never {
                throw new \RuntimeException('x');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame('it', Traducteur::langue());
    }

    public function testDansUneAutreLangue(): void
    {
        Traducteur::definirLangue('en');
        self::assertSame('Pannello di controllo', Traducteur::dans('it', fn () => Traducteur::t('Tableau de bord')));
        self::assertSame('en', Traducteur::langue());
        // Langue absente ou inconnue : celle de la requête.
        self::assertSame('Dashboard', Traducteur::dans(null, fn () => Traducteur::t('Tableau de bord')));
        self::assertSame('Dashboard', Traducteur::dans('de', fn () => Traducteur::t('Tableau de bord')));
    }

    public function testFiltreTwig(): void
    {
        Traducteur::definirLangue('it');
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            't' => "{{ 'Déconnexion'|trans }}|{{ 'Bonjour {nom}'|trans({nom: '<b>'}) }}",
        ]), ['autoescape' => 'html']);
        $twig->addFilter(Traducteur::filtreTwig());

        // Le résultat reste échappé (paramètres compris).
        self::assertSame('Esci|Bonjour &lt;b&gt;', $twig->render('t'));
    }
}
