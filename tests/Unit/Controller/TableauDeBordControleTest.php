<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\EquipementController;
use Epiclub\Controller\TableauDeBordController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [migration 019] Notification « EPI non vérifiés depuis plus de N mois »
 * (3 à 12, 6 par défaut) du tableau de bord, son réglage et le filtre de
 * la liste.
 */
final class TableauDeBordControleTest extends TestCase
{
    private ClubManager $club;
    private EquipementManager $equipements;
    private Session $session;

    private function preparer(string $role, bool $actif, ?int $nonVerifies, ?int $mois = null, string $avant = '2026-05-01'): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'u', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');

        $this->club = $this->createMock(ClubManager::class);
        $this->club->method('findParameters')->willReturn(['id' => 1, 'nom' => 'Club', 'alerte_controle_actif' => $actif ? 1 : 0]
            + ($mois === null ? [] : ['alerte_controle_mois' => $mois]));
        $this->equipements = $this->createMock(EquipementManager::class);
        $this->equipements->method('statsARenouveler')->willReturn(['total' => 0, 'echus' => 0]);
        if ($nonVerifies === null) {
            $this->equipements->expects(self::never())->method('countNonVerifies');
        } else {
            $this->equipements->expects(self::once())->method('countNonVerifies')
                ->with($avant)->willReturn($nonVerifies);
        }
    }

    private function controleur(): TableauDeBordController
    {
        return new class($this->session, $this->club, $this->equipements) extends TableauDeBordController {
            public function __construct(Session $s, private $c, private $e) { parent::__construct($s); }
            protected function clubManager(): ClubManager { return $this->c; }
            protected function clubManagerModules(): ClubManager { return $this->c; }
            protected function equipementManager(): EquipementManager { return $this->e; }
            protected function ticketManager(): \Epiclub\Domain\TicketManager { throw new \RuntimeException('Pas de tickets en test.'); }
            protected function aujourdhui(): \DateTimeImmutable { return new \DateTimeImmutable('2026-11-01'); }
            protected function updateLastActivity(): void {}
        };
    }

    public function testNotificationAvecLienVersLaListeFiltree(): void
    {
        $this->preparer('ROLE_CONTROLLEUR', true, 3);

        $html = (string) $this->controleur()->index(Request::create('/tableau_de_bord'))->getContent();

        self::assertStringContainsString('3 EPI non vérifiés', $html);
        self::assertStringContainsString('depuis plus de 6 mois', $html);
        self::assertStringContainsString('avant le 01/05/2026', $html);
        self::assertStringContainsString('/equipements?non_verifie_avant=2026-05-01&amp;order_by=date_dernier_controle&amp;order_dir=asc', $html);
    }

    public function testLeDelaiDuClubEstUtilise(): void
    {
        $this->preparer('ROLE_ADMIN', true, 2, 12, '2025-11-01');

        $html = (string) $this->controleur()->index(Request::create('/tableau_de_bord'))->getContent();

        self::assertStringContainsString('depuis plus de 12 mois', $html);
        self::assertStringContainsString('non_verifie_avant=2025-11-01', $html);
    }

    /** @return array<string, array{string, bool, ?int}> */
    public static function sansNotification(): array
    {
        return [
            'option désactivée'  => ['ROLE_ADMIN', false, null],
            'simple utilisateur' => ['ROLE_USER', true, null],
            'tout est vérifié'   => ['ROLE_CONTROLLEUR', true, 0],
        ];
    }

    #[DataProvider('sansNotification')]
    public function testPasDeNotification(string $role, bool $actif, ?int $nonVerifies): void
    {
        $this->preparer($role, $actif, $nonVerifies);

        $html = (string) $this->controleur()->index(Request::create('/tableau_de_bord'))->getContent();

        self::assertStringNotContainsString('alerte-controle', $html);
    }

    public function testLeReglageActiveLaNotification(): void
    {
        $this->preparer('ROLE_ADMIN', false, null);
        $this->club->expects(self::once())->method('save')
            ->with(['id' => 1, 'alerte_renouvellement_mois' => 3, 'alerte_controle_actif' => 1, 'alerte_controle_mois' => 9]);

        $response = $this->controleur()->reglages(Request::create('/admin/tableau-de-bord', 'POST', [
            'csrf_token' => 'csrf',
            'alerte_renouvellement_mois' => '3',
            'alerte_controle_actif' => '1',
            'alerte_controle_mois' => '9',
        ]));

        self::assertSame(302, $response->getStatusCode());
    }

    #[DataProvider('delaisInvalides')]
    public function testLeDelaiDoitEtreEntre3Et12Mois(string $delai): void
    {
        $this->preparer('ROLE_ADMIN', false, null);
        $this->club->expects(self::never())->method('save');

        $html = (string) $this->controleur()->reglages(Request::create('/admin/tableau-de-bord', 'POST', [
            'csrf_token' => 'csrf',
            'alerte_renouvellement_mois' => '3',
            'alerte_controle_mois' => $delai,
        ]))->getContent();

        self::assertStringContainsString('entre 3 et 12', $html);
    }

    /** @return array<string, array{string}> */
    public static function delaisInvalides(): array
    {
        return ['2' => ['2'], '13' => ['13'], 'vide' => [''], 'décimal' => ['6.5'], 'texte' => ['six']];
    }

    /** @return array<string, array{mixed, int}> */
    public static function delais(): array
    {
        return [
            'absent → 6' => [null, 6],
            'valeur BDD' => ['9', 9],
            'trop petit' => [1, 3],
            'trop grand' => [24, 12],
        ];
    }

    #[DataProvider('delais')]
    public function testNormaliserDureeControle(mixed $valeur, int $attendu): void
    {
        self::assertSame($attendu, TableauDeBordController::normaliserDureeControle($valeur));
    }

    // ------------------------------------------------------------------
    // Filtre de la liste (même périmètre que countNonVerifies)
    // ------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function equipements(): array
    {
        $epi = ['categorie' => ['est_epi' => 1], 'statut' => 0];
        return [
            'contrôle ancien'            => [$epi + ['date_dernier_controle' => '2026-04-30'], true],
            'contrôle récent'            => [$epi + ['date_dernier_controle' => '2026-05-01'], false],
            'mise en service ancienne'   => [$epi + ['date_mise_en_service' => '2025-01-01'], true],
            'mise en service récente'    => [$epi + ['date_mise_en_service' => '2026-09-01'], false],
            'contrôle prioritaire'       => [$epi + ['date_dernier_controle' => '2026-10-01', 'date_mise_en_service' => '2020-01-01'], false],
            'aucune date'                => [$epi, true],
            'pas un EPI'                 => [['categorie' => ['est_epi' => 0], 'statut' => 0], false],
            'hors service'               => [['categorie' => ['est_epi' => 1], 'statut' => 2], false],
            'égaré'                      => [['categorie' => ['est_epi' => 1], 'statut' => 4], false],
            'prêté reste concerné'       => [['categorie' => ['est_epi' => 1], 'statut' => 3, 'date_dernier_controle' => '2025-01-01'], true],
        ];
    }

    #[DataProvider('equipements')]
    public function testFiltreNonVerifie(array $equipement, bool $attendu): void
    {
        self::assertSame($attendu, EquipementController::nonVerifieAvant($equipement, '2026-05-01'));
    }
}
