<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\Langue;
use PHPUnit\Framework\TestCase;

/**
 * [Localisation] Choix de la langue : celle réglée pour le club, sinon
 * (« Automatique ») langue du navigateur, sinon français.
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

    public function testLangueDuClubPrioritaire(): void
    {
        self::assertSame('it', Langue::resoudre('it', 'es'));
    }

    public function testAutomatiqueLangueDuNavigateur(): void
    {
        self::assertSame('es', Langue::resoudre(null, 'es'));
    }

    public function testValeursInvalidesIgnorees(): void
    {
        self::assertSame('es', Langue::resoudre('de', 'es'));
        self::assertSame('fr', Langue::resoudre(null, 'de'));
        self::assertSame('fr', Langue::resoudre(null, null));
    }
}
