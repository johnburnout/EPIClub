<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\ControleScanController;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-20] Scan dans l'application avec un contrôle ouvert :
 * l'équipement est ajouté au contrôle puis sa fiche contrôle s'ouvre
 * (retour au scanner après enregistrement).
 */
final class ControleScanControllerTest extends TestCase
{
    private function appeler(string $role, ?array $controle, ?array $ligneExistante, bool $ajoutAttendu): array
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'c', 'role' => $role]);
        $session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn(['id' => 7, 'reference' => '26CAS007', 'libelle' => 'Casque']);
        $controles = $this->createMock(ControleManager::class);
        $controles->method('findActiveByUser')->willReturn($controle);
        $lignes = $this->createMock(ControleLigneManager::class);
        $lignes->method('findByControleAndEquipement')
            ->willReturnOnConsecutiveCalls($ligneExistante, ['id' => 55, 'controle_id' => 3, 'equipement_id' => 7]);
        $lignes->expects($ajoutAttendu ? self::once() : self::never())->method('addEquipement')->with(3, 7);

        $controller = new class($session, $equipements, $controles, $lignes) extends ControleScanController {
            public function __construct(Session $s, private $e, private $c, private $l) { parent::__construct($s); }
            protected function equipementManager(): EquipementManager { return $this->e; }
            protected function controleManager(): ControleManager { return $this->c; }
            protected function controleLigneManager(): ControleLigneManager { return $this->l; }
            protected function updateLastActivity() {}
        };

        $response = $controller->ajouter(Request::create('/controles/scanner/ajouter', 'POST', [
            'equipement_id' => '7', 'csrf_token' => 'csrf',
        ]));
        return json_decode((string) $response->getContent(), true);
    }

    public function testAjouteAuControleEtOuvreLaFicheControle(): void
    {
        $data = $this->appeler('ROLE_CONTROLLEUR', ['id' => 3, 'libelle' => 'Printemps'], null, true);

        self::assertSame('ajoute', $data['statut']);
        self::assertSame('/admin/controles/update-ligne/55?retour=scanner', $data['redirect']);
    }

    public function testDejaDansLeControleOuvreAussiLaFiche(): void
    {
        $data = $this->appeler('ROLE_CONTROLLEUR', ['id' => 3, 'libelle' => 'Printemps'], ['id' => 55], false);

        self::assertSame('deja', $data['statut']);
        self::assertSame('/admin/controles/update-ligne/55?retour=scanner', $data['redirect']);
    }

    public function testSansControleOuvertFicheEquipement(): void
    {
        $data = $this->appeler('ROLE_CONTROLLEUR', null, null, false);

        self::assertSame(['statut' => 'hors_controle', 'redirect' => '/equipements/equipement-7'], $data);
    }
}
