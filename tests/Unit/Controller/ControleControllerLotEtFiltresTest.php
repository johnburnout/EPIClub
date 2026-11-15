<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\FiltresListe;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableControleController;
use Epiclub\Tests\Unit\Controller\Support\TestableControleControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-11-15] Fiche contrôle d'un équipement : nombre d'éléments d'un
 * lot affiché ; au retour vers la fiche du contrôle (enregistrement ou
 * « Annuler »), ses filtres sont repris.
 */
final class ControleControllerLotEtFiltresTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 42, 'username' => 'c', 'role' => 'ROLE_CONTROLLEUR', 'controle_en_cours_id' => null]);
        $this->session->set('csrf_token', 'jeton');
    }

    public function testLeNombreDElementsDuLotEstAffiche(): void
    {
        $html = $this->ficheControle(['nombre' => 7, 'nombre_initial' => 10]);

        self::assertStringContainsString('<strong>Lot :</strong> 7 élément(s) restant(s) sur 10 initiaux', $html);
    }

    public function testRienPourUnEquipementIndividuel(): void
    {
        $html = $this->ficheControle(['nombre' => 1, 'nombre_initial' => 1]);

        self::assertStringNotContainsString('<strong>Lot :</strong>', $html);
    }

    public function testAnnulerRameneALaFicheDuControleFiltree(): void
    {
        $this->afficherFicheDuControle('/admin/controles/edit/5?lignes_statut=a_controler&lignes_search=corde');

        $html = $this->ficheControle(['nombre' => 1, 'nombre_initial' => 1]);

        self::assertStringContainsString('href="/admin/controles/edit/5?lignes_statut=a_controler&amp;lignes_search=corde"', $html);
    }

    public function testLEnregistrementRameneALaFicheDuControleFiltree(): void
    {
        $this->afficherFicheDuControle('/admin/controles/edit/5?eq_search=baudrier&eq_page=2');

        $requete = Request::create('/admin/controles/update-ligne/10', 'POST', [
            'statut' => 'controle_ok', 'remarque' => '', 'date_controle' => '2026-11-15 10:00:00', 'csrf_token' => 'jeton',
        ]);
        $requete->attributes->set('id', '10');
        $reponse = $this->controleur(['nombre' => 1, 'nombre_initial' => 1])->updateLigne($requete);

        self::assertSame('/admin/controles/edit/5?eq_search=baudrier&eq_page=2', $reponse->headers->get('Location'));
    }

    /** @param array<string, mixed> $equipement */
    private function ficheControle(array $equipement): string
    {
        $requete = Request::create('/admin/controles/update-ligne/10');
        $requete->attributes->set('id', '10');
        return (string) $this->controleur($equipement)->updateLigne($requete)->getContent();
    }

    private function afficherFicheDuControle(string $url): void
    {
        FiltresListe::memoriser($this->session, Request::create($url), new Response('fiche'));
    }

    /** @param array<string, mixed> $equipement */
    private function controleur(array $equipement): TestableControleController
    {
        $lignes = $this->createMock(ControleLigneManager::class);
        $lignes->method('findId')->willReturn([
            'id' => 10, 'controle_id' => 5, 'equipement_id' => 42, 'statut' => 'a_controler',
            'date_controle' => null, 'remarque' => '',
        ]);
        $lignes->method('save')->willReturn(true);

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn(['id' => 42, 'reference' => 'R1', 'libelle' => 'Corde', 'statut' => 0] + $equipement);

        $controles = $this->createMock(ControleManager::class);
        $controles->method('findId')->willReturn([
            'id' => 5, 'statut' => 'ouvert', 'controleur_id' => 42,
            'date_debut' => date('Y-m-d H:i:s'), 'hash_remarques' => null,
        ]);

        return (new TestableControleControllerBuilder($this))
            ->withSession($this->session)
            ->withControleManager($controles)
            ->withControleLigneManager($lignes)
            ->withEquipementManager($equipements)
            ->build();
    }
}
