<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\TableauDeBordController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\TicketManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-24] Demandes de modification sur le tableau de bord : à traiter
 * (administrateurs, selon le rôle requis) et traitées non consultées
 * (demandeur).
 */
final class TableauDeBordTicketsTest extends TestCase
{
    /** @var string[]|null Rôles passés à findOuvertsPourRoles() */
    private ?array $roles = null;

    private function ticket(int $id, string $statut): array
    {
        return ['id' => $id, 'equipement_id' => 4, 'equipement_reference' => '24COR003', 'equipement_supprime' => null,
                'statut' => $statut, 'modifications' => [['type' => 'categorie', 'remarque' => 'x']]];
    }

    private function page(string $role, array $ouverts = [], array $traites = []): string
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 7, 'username' => 'u', 'role' => $role]);
        $session->set('csrf_token', 'csrf');

        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['id' => 1, 'nom' => 'Club']);
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('statsARenouveler')->willReturn(['total' => 0, 'echus' => 0]);
        $tickets = $this->createMock(TicketManager::class);
        $tickets->method('findOuvertsPourRoles')->willReturnCallback(function (array $roles) use ($ouverts) {
            $this->roles = $roles;
            return $roles === [] ? [] : $ouverts;
        });
        $tickets->method('findClosNonVus')->with(7)->willReturn($traites);

        $controleur = new class($session, $club, $equipements, $tickets) extends TableauDeBordController {
            public function __construct(Session $s, private $c, private $e, private $t) { parent::__construct($s); }
            protected function clubManager(): ClubManager { return $this->c; }
            protected function clubManagerModules(): ClubManager { return $this->c; }
            protected function equipementManager(): EquipementManager { return $this->e; }
            protected function ticketManager(): TicketManager { return $this->t; }
            protected function updateLastActivity(): void {}
        };

        return (string) $controleur->index(Request::create('/tableau_de_bord'))->getContent();
    }

    public function testAdminVoitLesDemandesATraiterAvecLienVersLeFormulaire(): void
    {
        $html = $this->page('ROLE_ADMIN', [$this->ticket(50, 'ouvert'), $this->ticket(51, 'ouvert')]);

        self::assertSame(['ROLE_ADMIN'], $this->roles);
        self::assertStringContainsString('2 demandes de modification à traiter', $html);
        self::assertStringContainsString('href="/equipements/equipement_modification-4"', $html);
        self::assertStringContainsString('href="/tickets/ticket-51"', $html);
        self::assertStringContainsString(': Catégorie', $html);
    }

    public function testSuperAdminTraiteLesDeuxNiveaux(): void
    {
        $this->page('ROLE_SUPER_ADMIN');

        self::assertSame(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $this->roles);
    }

    public function testControleurNotifieDeSesDemandesTraitees(): void
    {
        $html = $this->page('ROLE_CONTROLLEUR', [], [$this->ticket(50, 'fait'), $this->ticket(52, 'refuse')]);

        self::assertSame([], $this->roles);
        self::assertStringNotContainsString('alerte-tickets', $html);
        self::assertStringContainsString('a été traitée', $html);
        self::assertStringContainsString('a été refusée', $html);
        self::assertStringContainsString('href="/tickets/ticket-52"', $html);
    }

    public function testRienPourUnSimpleUtilisateur(): void
    {
        $html = $this->page('ROLE_USER', [$this->ticket(50, 'ouvert')], [$this->ticket(52, 'fait')]);

        self::assertNull($this->roles);
        self::assertStringNotContainsString('ticket-', $html);
    }
}
