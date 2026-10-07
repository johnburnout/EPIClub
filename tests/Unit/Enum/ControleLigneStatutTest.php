<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Enum;

use Epiclub\Enum\ControleLigneStatut;
use PHPUnit\Framework\TestCase;

final class ControleLigneStatutTest extends TestCase
{
    public function testAllFourValuesExist(): void
    {
        $values = array_map(
            static fn(ControleLigneStatut $c) => $c->value,
            ControleLigneStatut::cases()
        );

        self::assertSame(
            ['a_controler', 'controle_ok', 'controle_ko', 'hors_service'],
            $values
        );
    }

    public function testTryFromReturnsEnumOnValidValue(): void
    {
        self::assertSame(
            ControleLigneStatut::CONTROLE_OK,
            ControleLigneStatut::tryFrom('controle_ok')
        );
    }

    public function testTryFromReturnsNullOnInvalidValue(): void
    {
        self::assertNull(ControleLigneStatut::tryFrom('hacked'));
        self::assertNull(ControleLigneStatut::tryFrom(''));
        self::assertNull(ControleLigneStatut::tryFrom('CONTROLE_OK'));
    }

    public function testLabelMatchesValues(): void
    {
        self::assertSame('À contrôler',  ControleLigneStatut::A_CONTROLER->label());
        self::assertSame('Contrôle OK',  ControleLigneStatut::CONTROLE_OK->label());
        self::assertSame('Contrôle KO',  ControleLigneStatut::CONTROLE_KO->label());
        self::assertSame('Hors service', ControleLigneStatut::HORS_SERVICE->label());
    }

    public function testIsTerminalIsFalseOnlyForAControler(): void
    {
        self::assertFalse(ControleLigneStatut::A_CONTROLER->isTerminal());
        self::assertTrue(ControleLigneStatut::CONTROLE_OK->isTerminal());
        self::assertTrue(ControleLigneStatut::CONTROLE_KO->isTerminal());
        self::assertTrue(ControleLigneStatut::HORS_SERVICE->isTerminal());
    }
}