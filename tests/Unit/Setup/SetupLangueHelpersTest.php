<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Setup;

use Epiclub\Engine\Traducteur;
use PHPUnit\Framework\TestCase;

/**
 * [Localisation] Langue et textes de l'installeur : choix ?lang=
 * mémorisé en session, sinon langue du navigateur ; setup_t() échappe
 * tout, setup_html() seulement les paramètres.
 */
final class SetupLangueHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/setup/includes/helpers.php';
        unset($_SESSION['setup_langue'], $_GET['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['setup_langue'], $_GET['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        Traducteur::definirLangue('fr');
    }

    public function testLangueDuNavigateurPuisChoixMemorise(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es-ES,es;q=0.9';
        self::assertSame('es', @setup_langue());

        $_GET['lang'] = 'it';
        self::assertSame('it', @setup_langue());

        // Pages suivantes, sans paramètre : le choix reste en session.
        unset($_GET['lang']);
        self::assertSame('it', @setup_langue());
    }

    public function testLangueInconnueIgnoree(): void
    {
        $_GET['lang'] = 'de';
        self::assertSame('fr', @setup_langue());
        self::assertArrayNotHasKey('setup_langue', $_SESSION);
    }

    public function testSetupTEchappeTexteEtParametres(): void
    {
        Traducteur::definirLangue('it');
        self::assertSame(
            'Errore durante l&#039;installazione: &lt;script&gt;',
            setup_t("Erreur lors de l'installation : {erreur}", ['erreur' => '<script>'])
        );
    }

    public function testSetupHtmlGardeLesBalisesEtEchappeLesParametres(): void
    {
        $html = setup_html(
            "Voici une liste de catégories préparée pour l'activité <strong>{activite}</strong>. Décochez celles dont vous n'avez pas besoin — vous pourrez toutes les modifier ou en ajouter ensuite dans <em>Administration → Catégories</em>.",
            ['activite' => '<b>x']
        );
        self::assertStringContainsString('<strong>&lt;b&gt;x</strong>', $html);
        self::assertStringContainsString('<em>', $html);
    }
}
