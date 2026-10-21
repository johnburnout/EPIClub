<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\EquipementController;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\PretLigneManager;
use Epiclub\Domain\PretManager;
use Epiclub\Engine\Session;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [Prêt de matériel] « Ajouter au prêt » depuis la liste et la fiche
 * des équipements (module activé, rôle contrôleur).
 */
final class EquipementControllerPretTest extends TestCase
{
    private function controleur(string $role, bool $moduleActif, ?array $pretOuvert = null): EquipementController
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 5, 'role' => $role, 'nom' => 'N', 'prenom' => 'P', 'username' => 'u']);
        $session->set('csrf_token', 'csrf');

        $equipement = ['id' => 7, 'reference' => 'CAS007', 'libelle' => 'Casque', 'statut' => 0, 'controle_en_cours' => 0,
            'categorie_id' => null, 'emplacement_id' => null, 'acquisition_id' => null, 'photo' => null, 'nombre' => 1,
            'date_fin_utilisation' => null, 'date_mise_en_service' => null, 'date_dernier_controle' => null, 'remarques' => null];
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn($equipement);
        $equipements->method('findAll')->willReturn([$equipement]);
        $equipements->method('getHistoriqueControles')->willReturn([]);
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);
        $categories->method('findIds')->willReturn([]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([]);
        $emplacements->method('findIds')->willReturn([]);
        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['pret_actif' => $moduleActif ? 1 : 0]);
        $prets = $this->createMock(PretManager::class);
        $prets->method('findEnSaisie')->willReturn([['id' => 12, 'emprunteur_prenom' => 'Marie', 'emprunteur_nom' => 'Dupont']]);
        $lignes = $this->createMock(PretLigneManager::class);
        $lignes->method('findPretOuvertPourEquipement')->willReturn($pretOuvert);
        $lignes->method('historiqueEquipement')->willReturn([]);

        return new class($session, $equipements, $categories, $emplacements, $this->createMock(AcquisitionManager::class), $club, $prets, $lignes) extends EquipementController {
            public function __construct(Session $s, private $e, private $c, private $em, private $a, private $club, private $p, private $l)
            {
                parent::__construct($s);
            }
            protected function equipementManager(): EquipementManager { return $this->e; }
            protected function categorieManager(): CategorieManager { return $this->c; }
            protected function emplacementManager(): EmplacementManager { return $this->em; }
            protected function acquisitionManager(): AcquisitionManager { return $this->a; }
            protected function clubManagerModules(): ClubManager { return $this->club; }
            protected function pretManager(): PretManager { return $this->p; }
            protected function pretLigneManager(): PretLigneManager { return $this->l; }
            protected function updateLastActivity() {}
        };
    }

    private function fiche(EquipementController $c): string
    {
        $r = Request::create('/equipements/equipement-7');
        $r->attributes->set('id', '7');
        return (string) $c->show($r)->getContent();
    }

    public function testFicheProposeLAjoutAuPretEnSaisie(): void
    {
        $html = $this->fiche($this->controleur('ROLE_CONTROLLEUR', true));

        self::assertStringContainsString('action="/prets/ajouter"', $html);
        self::assertStringContainsString('Prêt n° 12 — Marie Dupont', $html);
    }

    public function testFicheIndiqueLePretOuvert(): void
    {
        $html = $this->fiche($this->controleur('ROLE_CONTROLLEUR', true, [
            'id' => 9, 'statut' => 'en_cours', 'emprunteur_prenom' => 'Ana', 'emprunteur_nom' => 'Roc', 'date_retour_prevue' => '2026-10-30',
        ]));

        self::assertStringContainsString('prêt n° 9', $html);
        self::assertStringNotContainsString('action="/prets/ajouter"', $html);
    }

    public function testModuleDesactiveRienNEstAffiche(): void
    {
        $html = $this->fiche($this->controleur('ROLE_CONTROLLEUR', false));

        self::assertStringNotContainsString('/prets/', $html);
    }

    public function testSimpleUtilisateurNeVoitPasLesPrets(): void
    {
        $html = $this->fiche($this->controleur('ROLE_USER', true));

        self::assertStringNotContainsString('/prets/', $html);
    }

    public function testListeProposeLAjoutDeLaSelection(): void
    {
        $html = (string) $this->controleur('ROLE_CONTROLLEUR', true)->list(Request::create('/equipements'))->getContent();

        self::assertStringContainsString('id="form-pret"', $html);
        self::assertStringContainsString('form="form-pret"', $html);
        self::assertStringContainsString('>Prêts<', $html);
    }
}
