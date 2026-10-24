<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\TicketManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableTicketController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mime\Email;

/**
 * [2026-10-24] Demandes de modification (tickets) : demande depuis la
 * fiche, mails sans relance, consultation, traitement (fait / refusé).
 */
final class TicketControllerTest extends TestCase
{
    private Session $session;
    /** @var list<array{0:int,1:string,2:array}> Tickets créés (équipement, rôle, modifications) */
    private array $crees = [];
    /** @var list<Email> */
    private array $mails = [];
    /** @var int[] Utilisateurs marqués « mail de ticket envoyé » */
    private array $marques = [];
    private ?array $cloture = null;
    private bool $dejaTraite = false;
    private ?int $vu = null;
    private ?array $ticket = null;

    private const EQUIPEMENT = ['id' => 4, 'reference' => '24COR003', 'libelle' => 'Corde', 'nombre' => 3, 'nombre_initial' => 3];

    private function controller(string $role, int $userId = 7): TestableTicketController
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => $userId, 'username' => 'x', 'prenom' => 'Paul', 'nom' => 'Martin', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');

        $tickets = $this->createMock(TicketManager::class);
        $tickets->method('creer')->willReturnCallback(function (int $eq, int $dem, string $role, array $mods) {
            $this->crees[] = [$eq, $role, $mods];
            return 100 + count($this->crees);
        });
        $tickets->method('findId')->willReturnCallback(fn (int $id) => $id === 50 ? $this->ticket : null);
        $tickets->method('cloturer')->willReturnCallback(function (int $id, string $statut, int $par, ?string $reponse) {
            if ($this->dejaTraite) {
                return false;
            }
            $this->cloture = [$id, $statut, $par, $reponse];
            return true;
        });
        $tickets->method('marquerVu')->willReturnCallback(function (int $id) {
            $this->vu = $id;
        });
        $tickets->method('findOuvertsPourRoles')->willReturn([]);
        $tickets->method('findParDemandeur')->willReturn([]);

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturnCallback(fn (int $id) => $id === 4 ? self::EQUIPEMENT : null);

        $utilisateurs = $this->createMock(UtilisateurManager::class);
        $utilisateurs->method('findDestinatairesTicket')->willReturn([
            ['id' => 1, 'prenom' => 'Ada', 'nom' => 'A', 'email' => 'admin@club.test', 'role' => 'ROLE_ADMIN', 'last_activity' => null, 'ticket_mail_envoye_le' => null],
            ['id' => 2, 'prenom' => 'Sam', 'nom' => 'S', 'email' => 'sa@club.test', 'role' => 'ROLE_SUPER_ADMIN', 'last_activity' => null, 'ticket_mail_envoye_le' => null],
            ['id' => 3, 'prenom' => 'Zoé', 'nom' => 'Z', 'email' => 'absente@club.test', 'role' => 'ROLE_SUPER_ADMIN',
             'last_activity' => '2026-10-20 08:00:00', 'ticket_mail_envoye_le' => '2026-10-21 08:00:00'],
        ]);
        $utilisateurs->method('marquerMailTicket')->willReturnCallback(function (int $id) {
            $this->marques[] = $id;
        });

        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['nom' => 'Club', 'email' => 'club@club.test']);

        $mailer = $this->createMock(MailerServiceInterface::class);
        $mailer->method('sendEmail')->willReturnCallback(function (Email $e) {
            $this->mails[] = $e;
        });

        return new TestableTicketController($this->session, $tickets, $equipements, $utilisateurs, $club, $mailer);
    }

    private function demander(string $role, array $post = []): Response
    {
        $request = $post === []
            ? Request::create('/equipements/demande_modification-4')
            : Request::create('/equipements/demande_modification-4', 'POST', $post + ['csrf_token' => 'csrf']);
        $request->attributes->set('id', '4');
        return $this->controller($role)->demander($request);
    }

    private function destinataires(): array
    {
        return array_map(static fn (Email $e) => $e->getTo()[0]->getAddress(), $this->mails);
    }

    // ---------------- Demande ----------------

    public function testFormulaireSelonLeRole(): void
    {
        $controleur = (string) $this->demander('ROLE_CONTROLLEUR')->getContent();
        self::assertStringContainsString('value="dissociation"', $controleur);
        self::assertStringContainsString('value="categorie"', $controleur);

        $admin = (string) $this->demander('ROLE_ADMIN')->getContent();
        self::assertStringNotContainsString('value="dissociation"', $admin);
        self::assertStringContainsString('value="categorie"', $admin);

        self::assertStringContainsString('déjà de faire toutes ces modifications', (string) $this->demander('ROLE_SUPER_ADMIN')->getContent());
    }

    public function testUnTicketParRoleEtUnMailParPersonne(): void
    {
        $response = $this->demander('ROLE_CONTROLLEUR', [
            'types' => ['dissociation', 'categorie'],
            'remarques' => ['dissociation' => 'En 2 + 1', 'categorie' => 'C\'est une longe'],
        ]);

        self::assertSame('/equipements/equipement-4', $response->headers->get('Location'));
        self::assertSame([
            [4, 'ROLE_ADMIN', ['dissociation' => 'En 2 + 1']],
            [4, 'ROLE_SUPER_ADMIN', ['categorie' => 'C\'est une longe']],
        ], $this->crees);
        // Admin : ticket admin ; super-admin : les deux, en un seul mail ;
        // Zoé, pas reconnectée depuis son dernier mail : pas de relance.
        self::assertSame(['admin@club.test', 'sa@club.test'], $this->destinataires());
        self::assertSame([1, 2], $this->marques);
        self::assertStringContainsString('https://club.test/tickets/ticket-101', (string) $this->mails[1]->getHtmlBody());
        self::assertStringContainsString('https://club.test/tickets/ticket-102', (string) $this->mails[1]->getHtmlBody());
        self::assertStringNotContainsString('ticket-102', (string) $this->mails[0]->getHtmlBody());
        self::assertStringContainsString('2 tickets', implode(' ', $this->session->getFlashBag()->peek('success')));
    }

    public function testRemarqueObligatoire(): void
    {
        $html = (string) $this->demander('ROLE_ADMIN', ['types' => ['reference'], 'remarques' => ['reference' => '']])->getContent();

        self::assertSame([], $this->crees);
        self::assertStringContainsString('la remarque est obligatoire', $html);
        self::assertMatchesRegularExpression('/value="reference"\s+id="type_reference" checked/', $html);
    }

    public function testAdminNeDemandePasCeQuIlPeutFaire(): void
    {
        $this->demander('ROLE_ADMIN', ['types' => ['statut'], 'remarques' => ['statut' => 'x']]);

        self::assertSame([], $this->crees);
        self::assertSame([], $this->mails);
    }

    public function testInterditAUnUtilisateur(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->demander('ROLE_USER');
    }

    // ---------------- Consultation ----------------

    private function ticket(string $statut, string $role = 'ROLE_ADMIN', int $demandeur = 7): void
    {
        $this->ticket = [
            'id' => 50, 'equipement_id' => 4, 'equipement_reference' => '24COR003', 'equipement_libelle' => 'Corde',
            'equipement_acquisition_id' => 9, 'equipement_supprime' => null, 'demandeur_id' => $demandeur,
            'demandeur_prenom' => 'Paul', 'demandeur_nom' => 'Martin', 'role_requis' => $role, 'statut' => $statut,
            'date_creation' => '2026-10-24 10:00:00', 'traite_par' => null, 'traite_par_prenom' => null,
            'traite_par_nom' => null, 'date_traitement' => null, 'reponse' => null, 'vu_par_demandeur' => 0,
            'modifications' => [['type' => 'dissociation', 'remarque' => 'En 2 + 1']],
        ];
    }

    private function voir(string $role, int $userId = 7): string
    {
        $request = Request::create('/tickets/ticket-50');
        $request->attributes->set('id', '50');
        return (string) $this->controller($role, $userId)->voir($request)->getContent();
    }

    public function testLeTraitantVoitLeFormulaireEtLeLien(): void
    {
        $this->ticket('ouvert');
        $html = $this->voir('ROLE_ADMIN', 1);

        self::assertStringContainsString('action="/tickets/ticket_traiter-50"', $html);
        self::assertStringContainsString('href="/equipements/equipement_modification-4"', $html);
        self::assertStringContainsString('Dissocier le lot', $html);
    }

    public function testLeDemandeurVoitSansTraiterEtMarqueVuUneFoisClos(): void
    {
        $this->ticket('ouvert');
        self::assertStringNotContainsString('ticket_traiter', $this->voir('ROLE_CONTROLLEUR'));
        self::assertNull($this->vu);

        $this->ticket('refuse');
        $this->voir('ROLE_CONTROLLEUR');
        self::assertSame(50, $this->vu);
    }

    public function testNiDemandeurNiCapable(): void
    {
        $this->ticket('ouvert', 'ROLE_SUPER_ADMIN', 7);
        $this->expectException(AccessDeniedException::class);
        $this->voir('ROLE_ADMIN', 1);
    }

    // ---------------- Traitement ----------------

    private function traiter(string $role, array $post): Response
    {
        $request = Request::create('/tickets/ticket_traiter-50', 'POST', $post + ['csrf_token' => 'csrf']);
        $request->attributes->set('id', '50');
        return $this->controller($role, 1)->traiter($request);
    }

    public function testFaitSansReponse(): void
    {
        $this->ticket('ouvert');
        $response = $this->traiter('ROLE_ADMIN', ['decision' => 'fait']);

        self::assertSame('/tickets', $response->headers->get('Location'));
        self::assertSame([50, 'fait', 1, null], $this->cloture);
    }

    public function testRefusAvecMotifObligatoire(): void
    {
        $this->ticket('ouvert');
        $this->traiter('ROLE_ADMIN', ['decision' => 'refuse', 'reponse' => ' ']);
        self::assertNull($this->cloture);
        self::assertStringContainsString('motif du refus', implode(' ', $this->session->getFlashBag()->peek('error')));

        $this->traiter('ROLE_ADMIN', ['decision' => 'refuse', 'reponse' => 'Lot déjà dissocié']);
        self::assertSame([50, 'refuse', 1, 'Lot déjà dissocié'], $this->cloture);
    }

    public function testDejaTraiteParQuelquUnDAutre(): void
    {
        $this->ticket('ouvert');
        $this->dejaTraite = true;
        $this->traiter('ROLE_ADMIN', ['decision' => 'fait']);

        self::assertStringContainsString('déjà été traité', implode(' ', $this->session->getFlashBag()->peek('note')));
    }

    public function testUnAdminNeTraitePasUnTicketDeSuperAdmin(): void
    {
        $this->ticket('ouvert', 'ROLE_SUPER_ADMIN');
        $this->expectException(AccessDeniedException::class);
        $this->traiter('ROLE_ADMIN', ['decision' => 'fait']);
    }

    public function testDecisionInvalide(): void
    {
        $this->ticket('ouvert');
        $this->traiter('ROLE_ADMIN', ['decision' => 'supprimer']);

        self::assertNull($this->cloture);
    }
}
