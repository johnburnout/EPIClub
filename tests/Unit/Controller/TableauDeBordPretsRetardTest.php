<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\TableauDeBordController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\PretManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [Prêt de matériel] Notification « prêts en retard » du tableau de bord.
 */
final class TableauDeBordPretsRetardTest extends TestCase
{
    private function page(string $role, bool $moduleActif, int $enRetard): string
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'u', 'role' => $role]);
        $session->set('csrf_token', 'csrf');

        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['id' => 1, 'nom' => 'Club', 'pret_actif' => $moduleActif ? 1 : 0]);
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('statsARenouveler')->willReturn(['total' => 0, 'echus' => 0]);
        $prets = $this->createMock(PretManager::class);
        $prets->method('countEnRetard')->with('2026-10-21')->willReturn($enRetard);

        $controleur = new class($session, $club, $equipements, $prets) extends TableauDeBordController {
            public function __construct(Session $s, private $c, private $e, private $p) { parent::__construct($s); }
            protected function clubManager(): ClubManager { return $this->c; }
            protected function clubManagerModules(): ClubManager { return $this->c; }
            protected function equipementManager(): EquipementManager { return $this->e; }
            protected function pretManager(): PretManager { return $this->p; }
            protected function ticketManager(): \Epiclub\Domain\TicketManager { throw new \RuntimeException('Pas de tickets en test.'); }
            protected function aujourdhui(): \DateTimeImmutable { return new \DateTimeImmutable('2026-10-21'); }
            protected function updateLastActivity(): void {}
        };

        return (string) $controleur->index(Request::create('/tableau_de_bord'))->getContent();
    }

    public function testNotificationDesPretsEnRetard(): void
    {
        $html = $this->page('ROLE_CONTROLLEUR', true, 2);

        self::assertStringContainsString('2 prêts en retard', $html);
        self::assertStringContainsString('href="/prets?retard=1"', $html);
    }

    /** @return array<string, array{string, bool, int}> */
    public static function sansNotification(): array
    {
        return [
            'aucun retard'      => ['ROLE_CONTROLLEUR', true, 0],
            'module désactivé'  => ['ROLE_CONTROLLEUR', false, 3],
            'simple utilisateur' => ['ROLE_USER', true, 3],
        ];
    }

    #[DataProvider('sansNotification')]
    public function testPasDeNotification(string $role, bool $moduleActif, int $enRetard): void
    {
        self::assertStringNotContainsString('alerte-prets-retard', $this->page($role, $moduleActif, $enRetard));
    }
}
