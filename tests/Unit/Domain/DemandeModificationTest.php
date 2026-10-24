<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\DemandeModification;
use PHPUnit\Framework\TestCase;

/**
 * [2026-10-24] Demandes de modification (tickets) : modifications
 * demandables selon le rôle, répartition par rôle requis, destinataires
 * des mails (pas de relance avant reconnexion).
 */
final class DemandeModificationTest extends TestCase
{
    private const UNIQUE = ['id' => 4, 'nombre' => 1, 'nombre_initial' => 1];
    private const LOT = ['id' => 4, 'nombre' => 3, 'nombre_initial' => 5];

    public function testControleurDemandeToutSaufLEmplacement(): void
    {
        $types = array_keys(DemandeModification::typesDemandables('ROLE_CONTROLLEUR', self::LOT));

        self::assertSame(array_keys(DemandeModification::TYPES), $types);
        self::assertNotContains('emplacement', $types);
    }

    public function testDissociationEtNombreSeulementPourUnLot(): void
    {
        $types = array_keys(DemandeModification::typesDemandables('ROLE_CONTROLLEUR', self::UNIQUE));

        self::assertNotContains('dissociation', $types);
        self::assertNotContains('nombre', $types);
        self::assertContains('regroupement', $types);
    }

    public function testAdminNeDemandeQueCeQuiRequiertUnSuperAdmin(): void
    {
        self::assertSame(
            ['reference', 'categorie', 'lot', 'acquisition'],
            array_keys(DemandeModification::typesDemandables('ROLE_ADMIN', self::LOT))
        );
        self::assertSame([], DemandeModification::typesDemandables('ROLE_SUPER_ADMIN', self::LOT));
    }

    public function testRepartitionParRoleRequis(): void
    {
        $demandables = DemandeModification::typesDemandables('ROLE_CONTROLLEUR', self::LOT);

        self::assertSame(
            ['ROLE_ADMIN' => ['dissociation' => 'En 2 lots'], 'ROLE_SUPER_ADMIN' => ['categorie' => 'Corde, pas casque']],
            DemandeModification::repartir($demandables, ['categorie', 'dissociation'],
                ['dissociation' => ' En 2 lots ', 'categorie' => 'Corde, pas casque', 'statut' => 'ignorée'])
        );
    }

    public function testRepartitionRefusee(): void
    {
        $demandables = DemandeModification::typesDemandables('ROLE_ADMIN', self::LOT);

        self::assertStringContainsString('au moins une', (string) DemandeModification::repartir($demandables, [], []));
        self::assertStringContainsString('obligatoire', (string) DemandeModification::repartir($demandables, ['reference'], ['reference' => '  ']));
        self::assertStringContainsString('dépasser', (string) DemandeModification::repartir($demandables, ['reference'], ['reference' => str_repeat('a', 2001)]));
        // Un admin ne demande pas ce qu'il peut faire lui-même.
        self::assertStringContainsString('non autorisée', (string) DemandeModification::repartir($demandables, ['statut'], ['statut' => 'x']));
        self::assertStringContainsString('non autorisée', (string) DemandeModification::repartir($demandables, ['inconnu'], ['inconnu' => 'x']));
    }

    public function testDestinatairesSansRelanceAvantReconnexion(): void
    {
        $utilisateurs = [
            ['id' => 1, 'role' => 'ROLE_ADMIN', 'email' => 'a@x', 'last_activity' => null, 'ticket_mail_envoye_le' => null],
            ['id' => 2, 'role' => 'ROLE_ADMIN', 'email' => 'b@x', 'last_activity' => '2026-10-24 09:00:00', 'ticket_mail_envoye_le' => '2026-10-24 10:00:00'],
            ['id' => 3, 'role' => 'ROLE_SUPER_ADMIN', 'email' => 'c@x', 'last_activity' => '2026-10-24 11:00:00', 'ticket_mail_envoye_le' => '2026-10-24 10:00:00'],
            ['id' => 4, 'role' => 'ROLE_ADMIN', 'email' => '', 'last_activity' => null, 'ticket_mail_envoye_le' => null],
            ['id' => 5, 'role' => 'ROLE_ADMIN', 'email' => 'demandeur@x', 'last_activity' => null, 'ticket_mail_envoye_le' => null],
        ];

        // 1 : jamais prévenu ; 2 : pas reconnecté depuis le dernier mail ;
        // 3 : reconnecté ; 4 : sans email ; 5 : demandeur.
        $ids = fn (array $liste) => array_map(static fn ($u) => $u['id'], $liste);
        self::assertSame([1, 3], $ids(DemandeModification::destinatairesMail($utilisateurs, 'ROLE_ADMIN', 5)));
        self::assertSame([3], $ids(DemandeModification::destinatairesMail($utilisateurs, 'ROLE_SUPER_ADMIN', 5)));
    }
}
