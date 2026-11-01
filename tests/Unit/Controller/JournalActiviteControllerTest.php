<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\JournalActiviteController;
use Epiclub\Domain\JournalActiviteManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [migration 019] Journal d'activité : consultation et export
 * (administrateurs), réinitialisation (super administrateurs, saisie de
 * « REINITIALISER »).
 */
final class JournalActiviteControllerTest extends TestCase
{
    private Session $session;
    /** @var JournalActiviteManager&MockObject */
    private JournalActiviteManager $lecture;
    /** @var JournalActiviteManager&MockObject */
    private JournalActiviteManager $ecriture;

    protected function setUp(): void
    {
        $this->lecture = $this->createMock(JournalActiviteManager::class);
        $this->ecriture = $this->createMock(JournalActiviteManager::class);
    }

    private function connecter(string $role): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 7, 'prenom' => 'Ada', 'nom' => 'Lovelace', 'username' => 'ada', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');
    }

    private function controleur(): JournalActiviteController
    {
        return new class($this->session, $this->lecture, $this->ecriture) extends JournalActiviteController {
            public function __construct(Session $s, private $l, private $e) { parent::__construct($s); }
            protected function lectureManager(): JournalActiviteManager { return $this->l; }
            protected function journalActiviteManager(): ?JournalActiviteManager { return $this->e; }
            protected function maintenant(): \DateTimeImmutable { return new \DateTimeImmutable('2026-11-01 10:20:30'); }
            protected function updateLastActivity(): void {}
        };
    }

    private static function entree(array $autres = []): array
    {
        return $autres + [
            'id' => 1,
            'date_action' => '2026-10-31 18:05:00',
            'type' => 'connexion',
            'utilisateur' => 'Ada Lovelace (ada)',
            'action' => 'Connexion',
            'ip' => '192.0.2.4',
        ];
    }

    // ------------------------------------------------------------------
    // Accès
    // ------------------------------------------------------------------

    public function testUnControleurNeVoitPasLeJournal(): void
    {
        $this->connecter('ROLE_CONTROLLEUR');
        $this->expectException(AccessDeniedException::class);
        $this->controleur()->index(Request::create('/admin/journal-activite'));
    }

    public function testUnAdministrateurNePeutPasReinitialiser(): void
    {
        $this->connecter('ROLE_ADMIN');
        $this->lecture->expects(self::never())->method('reinitialiser');
        $this->expectException(AccessDeniedException::class);
        $this->controleur()->reinitialiser($this->post(['confirmation' => 'REINITIALISER']));
    }

    // ------------------------------------------------------------------
    // Consultation
    // ------------------------------------------------------------------

    public function testListeFiltreeEtPaginee(): void
    {
        $this->connecter('ROLE_ADMIN');
        $filtres = ['type' => 'pret', 'du' => '2026-10-01', 'au' => null, 'q' => 'ada'];
        $this->lecture->method('compter')->with($filtres)->willReturn(30);
        $this->lecture->expects(self::once())->method('rechercher')
            ->with($filtres, 10, 10)
            ->willReturn([self::entree(['type' => 'pret', 'action' => 'Création du prêt n° 4'])]);

        $html = (string) $this->controleur()->index(Request::create('/admin/journal-activite', 'GET', [
            'type' => 'pret', 'du' => '2026-10-01', 'au' => '2026-02-31', 'q' => ' ada ', 'page' => '2',
        ]))->getContent();

        self::assertStringContainsString('Création du prêt n° 4', $html);
        self::assertStringContainsString('31/10/2026 18:05:00', $html);
        self::assertStringContainsString('/admin/journal-activite/export?type=pret&amp;du=2026-10-01&amp;q=ada', $html);
        self::assertStringContainsString('Page 2 sur 3', $html);
        self::assertStringNotContainsString('Réinitialiser le journal', $html, 'Réservé aux super administrateurs');
    }

    public function testLeSuperAdministrateurVoitLaReinitialisation(): void
    {
        $this->connecter('ROLE_SUPER_ADMIN');
        $this->lecture->method('compter')->willReturn(0);
        $this->lecture->method('rechercher')->willReturn([]);

        $html = (string) $this->controleur()->index(Request::create('/admin/journal-activite'))->getContent();

        self::assertStringContainsString('Réinitialiser le journal', $html);
        self::assertStringContainsString('action="/admin/journal-activite/reinitialiser"', $html);
    }

    // ------------------------------------------------------------------
    // Export texte
    // ------------------------------------------------------------------

    public function testExportTexte(): void
    {
        $this->connecter('ROLE_ADMIN');
        $this->lecture->expects(self::once())->method('rechercher')
            ->with(['type' => 'connexion', 'du' => null, 'au' => null, 'q' => null])
            ->willReturn([
                self::entree(),
                self::entree(['utilisateur' => '', 'action' => "Échec de connexion (identifiant « x\ny »)", 'ip' => null]),
            ]);

        $response = $this->controleur()->export(Request::create('/admin/journal-activite/export', 'GET', ['type' => 'connexion']));
        $texte = (string) $response->getContent();

        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="journal_activite_2026-11-01_102030.txt"', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString("Exporté le 01/11/2026 à 10:20:30\r\nFiltres : type : Connexion\r\n2 entrées\r\n", $texte);
        self::assertStringContainsString("31/10/2026 18:05:00 | Connexion | Ada Lovelace (ada) | Connexion | IP 192.0.2.4\r\n", $texte);
        self::assertStringContainsString("| Connexion | — | Échec de connexion (identifiant « x y ») | IP —\r\n", $texte);
    }

    // ------------------------------------------------------------------
    // Réinitialisation
    // ------------------------------------------------------------------

    public function testReinitialisationRefuseeSansLeMotDeConfirmation(): void
    {
        $this->connecter('ROLE_SUPER_ADMIN');
        $this->lecture->expects(self::never())->method('reinitialiser');
        $this->ecriture->expects(self::never())->method('enregistrer');

        $response = $this->controleur()->reinitialiser($this->post(['confirmation' => 'reinitialiser']));

        self::assertSame('/admin/journal-activite', $response->headers->get('Location'));
        self::assertStringContainsString('REINITIALISER', implode(' ', $this->session->getFlashBag()->get('error')));
    }

    public function testReinitialisationEstElleMemeJournalisee(): void
    {
        $this->connecter('ROLE_SUPER_ADMIN');
        $this->lecture->expects(self::once())->method('reinitialiser')->willReturn(42);
        $this->ecriture->expects(self::once())->method('enregistrer')->with(
            JournalActiviteManager::TYPE_JOURNAL,
            'Réinitialisation du journal (42 entrées supprimées)',
            self::callback(static fn ($u) => ($u['id'] ?? null) === 7),
            '127.0.0.1',
        );

        $response = $this->controleur()->reinitialiser($this->post(['confirmation' => ' REINITIALISER ']));

        self::assertSame(302, $response->getStatusCode());
        self::assertNotEmpty($this->session->getFlashBag()->get('success'));
    }

    private function post(array $donnees): Request
    {
        return Request::create('/admin/journal-activite/reinitialiser', 'POST', $donnees + ['csrf_token' => 'csrf']);
    }
}
