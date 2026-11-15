<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\Langue;
use PHPUnit\Framework\TestCase;

/**
 * [Localisation] Choix de la langue : préférence enregistrée de
 * l'utilisateur, sinon choix fait dans la session (visiteur non
 * connecté), sinon langue du navigateur, sinon français.
 */
final class LangueTest extends TestCase
{
    public function testLanguesDisponibles(): void
    {
        self::assertSame(['fr', 'en', 'es', 'it'], array_keys(Langue::DISPONIBLES));
        self::assertTrue(Langue::estDisponible('es'));
        self::assertFalse(Langue::estDisponible('de'));
        self::assertFalse(Langue::estDisponible(null));
        self::assertFalse(Langue::estDisponible(''));
    }

    public function testPreferenceUtilisateurPrioritaire(): void
    {
        self::assertSame('it', Langue::resoudre('it', 'en', 'es'));
    }

    public function testChoixDeSessionEnsuite(): void
    {
        self::assertSame('en', Langue::resoudre(null, 'en', 'es'));
    }

    public function testNavigateurEnsuite(): void
    {
        self::assertSame('es', Langue::resoudre(null, null, 'es'));
    }

    public function testValeursInvalidesIgnorees(): void
    {
        self::assertSame('es', Langue::resoudre('de', 'xx', 'es'));
        self::assertSame('fr', Langue::resoudre(null, null, 'de'));
        self::assertSame('fr', Langue::resoudre(null, null, null));
    }
}
