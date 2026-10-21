<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Enum;

use Epiclub\Enum\ControleLigneStatut;
use Epiclub\Enum\EquipementStatut;
use Epiclub\Enum\PretEtatRetour;
use Epiclub\Enum\PretStatut;
use PHPUnit\Framework\TestCase;

/**
 * [Prêt de matériel — migration 016] Statuts « Prêté » / « Égaré » et
 * statuts de la fiche de prêt.
 */
final class PretStatutsTest extends TestCase
{
    public function testStatutsEquipement(): void
    {
        self::assertSame('Prêté', EquipementStatut::PRETE->label());
        self::assertSame('Égaré', EquipementStatut::EGARE->label());
        self::assertSame(3, EquipementStatut::PRETE->value);
        self::assertSame(4, EquipementStatut::EGARE->value);
    }

    public function testStatutsReservesExclusDeLaSaisieManuelle(): void
    {
        self::assertSame(
            ['Disponible' => 0, 'En maintenance' => 1],
            EquipementStatut::forSelectManuel()
        );
        foreach ([EquipementStatut::HORS_SERVICE, EquipementStatut::PRETE, EquipementStatut::EGARE] as $s) {
            self::assertTrue($s->estReserve());
        }
        self::assertFalse(EquipementStatut::DISPONIBLE->estReserve());
        self::assertFalse(EquipementStatut::EN_MAINTENANCE->estReserve());
    }

    public function testChaqueStatutAUneCouleurEtUnBadge(): void
    {
        $couleurs = [];
        foreach (EquipementStatut::cases() as $s) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $s->couleur());
            self::assertNotSame('', $s->badge());
            $couleurs[] = $s->couleur();
        }
        self::assertSame($couleurs, array_unique($couleurs), 'Couleurs distinctes dans la liste');
    }

    public function testEgareDisponibleDansLeControle(): void
    {
        self::assertSame(ControleLigneStatut::EGARE, ControleLigneStatut::tryFrom('egare'));
        self::assertTrue(ControleLigneStatut::EGARE->isTerminal());
    }

    public function testEtatsDeRetour(): void
    {
        self::assertSame(EquipementStatut::DISPONIBLE, PretEtatRetour::DISPONIBLE->equipementStatut());
        self::assertSame(EquipementStatut::EN_MAINTENANCE, PretEtatRetour::MAINTENANCE->equipementStatut());
        self::assertSame(EquipementStatut::EGARE, PretEtatRetour::EGARE->equipementStatut());
        self::assertNull(PretEtatRetour::tryFrom('hors_service'));
    }

    public function testStatutsDeFiche(): void
    {
        self::assertSame(['saisie', 'en_cours', 'termine'], array_map(static fn($c) => $c->value, PretStatut::cases()));
        self::assertFalse(PretStatut::SAISIE->exportable());
        self::assertTrue(PretStatut::EN_COURS->exportable());
        self::assertTrue(PretStatut::TERMINE->exportable());
    }
}
