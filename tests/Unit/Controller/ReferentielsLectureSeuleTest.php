<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableCategorieControllerBuilder;
use Epiclub\Tests\Unit\Controller\Support\TestableEmplacementControllerBuilder;
use Epiclub\Tests\Unit\Controller\Support\TestableFournisseurControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-25] Catégories, emplacements et fournisseurs : consultables en
 * lecture seule par tout utilisateur connecté (boutons de modification
 * réservés aux administrateurs), avec un lien vers les équipements
 * affectés à chacun.
 */
final class ReferentielsLectureSeuleTest extends TestCase
{
    private function session(string $role): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'u', 'role' => $role]);
        $session->set('csrf_token', 'csrf');
        return $session;
    }

    private function equipements(): EquipementManager
    {
        $m = $this->createMock(EquipementManager::class);
        $m->method('compterPar')->willReturnCallback(fn (string $colonne) => $colonne === 'categorie_id' ? [1 => 3] : [5 => 1]);
        $m->method('compterParFournisseur')->willReturn([7 => 12]);
        return $m;
    }

    private function avecId(string $chemin, int $id): Request
    {
        $request = Request::create($chemin);
        $request->attributes->set('id', (string) $id);
        return $request;
    }

    // ---------------- Catégories ----------------

    private function categories(string $role): \Epiclub\Tests\Unit\Controller\Support\TestableCategorieController
    {
        $manager = $this->createMock(CategorieManager::class);
        $manager->method('findAll')->willReturn([
            ['id' => 1, 'libelle' => 'Casques', 'code' => 'CAS', 'description' => '', 'est_epi' => 1, 'duree_max_utilisation' => 1, 'image' => null],
            ['id' => 2, 'libelle' => 'Tapis', 'code' => 'TAP', 'description' => '', 'est_epi' => 0, 'duree_max_utilisation' => 0, 'image' => null],
        ]);
        $manager->method('findId')->willReturnCallback(fn (int $id) => $id === 1
            ? ['id' => 1, 'libelle' => 'Casques', 'code' => 'CAS', 'description' => '', 'est_epi' => 1, 'duree_max_utilisation' => 1, 'image' => null]
            : null);
        $controller = (new TestableCategorieControllerBuilder($this))
            ->withSession($this->session($role))->withCategorieManager($manager)->build();
        $controller->equipementManagerOverride = $this->equipements();
        return $controller;
    }

    public function testCategoriesEnLectureSeulePourUnUtilisateur(): void
    {
        $html = (string) $this->categories('ROLE_USER')->list(Request::create('/admin/categories'))->getContent();

        self::assertStringContainsString('href="/equipements?categorie=1"', $html);
        self::assertStringContainsString('3 équipements', $html);
        self::assertStringContainsString('Aucun', $html);
        self::assertStringNotContainsString('categorie_modification-', $html);
        self::assertStringNotContainsString('/admin/categories/supprimer', $html);
        self::assertStringNotContainsString('/admin/categories/nouvelle', $html);
    }

    public function testCategoriesModifiablesPourUnAdmin(): void
    {
        $html = (string) $this->categories('ROLE_ADMIN')->list(Request::create('/admin/categories'))->getContent();

        self::assertStringContainsString('categorie_modification-1', $html);
        self::assertStringContainsString('/admin/categories/nouvelle', $html);
    }

    public function testRechercheFiltreEtTriDesCategories(): void
    {
        $liste = fn (string $query) => (string) $this->categories('ROLE_USER')
            ->list(Request::create('/admin/categories?' . $query))->getContent();

        $html = $liste('');
        self::assertStringContainsString('name="q"', $html);
        self::assertStringContainsString('<option value="equipements"', $html);
        self::assertStringContainsString('2 catégories', $html);
        self::assertLessThan(strpos($html, '<td>Tapis</td>'), strpos($html, '<td>Casques</td>'));

        $html = $liste('order_by=libelle&order_dir=desc');
        self::assertLessThan(strpos($html, '<td>Casques</td>'), strpos($html, '<td>Tapis</td>'));

        $html = $liste('q=tap');
        self::assertStringContainsString('<td>Tapis</td>', $html);
        self::assertStringNotContainsString('<td>Casques</td>', $html);
        self::assertStringContainsString('1 catégorie sur 2', $html);

        $html = $liste('epi=non');
        self::assertStringContainsString('<td>Tapis</td>', $html);
        self::assertStringNotContainsString('<td>Casques</td>', $html);

        $html = $liste('equipements=avec');
        self::assertStringContainsString('<td>Casques</td>', $html);
        self::assertStringNotContainsString('<td>Tapis</td>', $html);

        self::assertStringContainsString('Aucune catégorie ne correspond', $liste('q=zzz'));
    }

    public function testFicheCategorie(): void
    {
        $html = (string) $this->categories('ROLE_USER')->show($this->avecId('/admin/categories/categorie-1', 1))->getContent();

        self::assertStringContainsString('Voir les 3 équipements', $html);
        self::assertStringNotContainsString('categorie_modification-1', $html);
    }

    // ---------------- Emplacements ----------------

    public function testEmplacements(): void
    {
        $manager = $this->createMock(EmplacementManager::class);
        $manager->method('findAll')->willReturn([['id' => 5, 'libelle' => 'Armoire', 'description' => 'Local']]);
        $manager->method('findId')->willReturn(['id' => 5, 'libelle' => 'Armoire', 'description' => 'Local', 'image' => null]);
        $controller = (new TestableEmplacementControllerBuilder($this))
            ->withSession($this->session('ROLE_USER'))->withEmplacementManager($manager)->build();
        $controller->equipementManagerOverride = $this->equipements();

        $liste = (string) $controller->list(Request::create('/admin/emplacements'))->getContent();
        self::assertStringContainsString('href="/equipements?emplacement=5"', $liste);
        self::assertStringContainsString('1 équipement<', $liste);
        self::assertStringNotContainsString('emplacement_modification-', $liste);

        // Fiche en lecture seule (plus de formulaire)
        $fiche = (string) $controller->show($this->avecId('/admin/emplacements/emplacement-5', 5))->getContent();
        self::assertStringContainsString('Fiche emplacement', $fiche);
        self::assertStringContainsString("Voir l'équipement", $fiche);
        self::assertStringNotContainsString('<form method="post">', $fiche);
    }

    // ---------------- Fournisseurs ----------------

    private function fournisseurs(string $role): \Epiclub\Tests\Unit\Controller\Support\TestableFournisseurController
    {
        $manager = $this->createMock(FournisseurManager::class);
        $manager->method('findAll')->willReturn([['id' => 7, 'nom' => 'Alpin Shop', 'email' => 'a@shop.test', 'phone' => '01']]);
        $manager->method('findId')->willReturn(['id' => 7, 'nom' => 'Alpin Shop', 'email' => 'a@shop.test', 'phone' => '01']);
        $controller = (new TestableFournisseurControllerBuilder($this))
            ->withSession($this->session($role))->withFournisseurManager($manager)->build();
        $controller->equipementManagerOverride = $this->equipements();
        return $controller;
    }

    public function testFournisseursConsultablesParUnUtilisateur(): void
    {
        $liste = (string) $this->fournisseurs('ROLE_USER')->list(Request::create('/admin/fournisseurs'))->getContent();
        self::assertStringContainsString('href="/equipements?fournisseur=7"', $liste);
        self::assertStringContainsString('12 équipements', $liste);
        self::assertStringNotContainsString('fournisseur_modification-', $liste);

        $fiche = (string) $this->fournisseurs('ROLE_USER')->show($this->avecId('/admin/fournisseurs/fournisseur-7', 7))->getContent();
        self::assertStringContainsString('Voir les 12 équipements', $fiche);
        self::assertStringNotContainsString('fournisseur_modification-7', $fiche);
    }

    public function testFournisseurModifiablePourUnAdmin(): void
    {
        $fiche = (string) $this->fournisseurs('ROLE_ADMIN')->show($this->avecId('/admin/fournisseurs/fournisseur-7', 7))->getContent();

        self::assertStringContainsString('fournisseur_modification-7', $fiche);
    }

    public function testModificationToujoursReserveeAuxAdmins(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->fournisseurs('ROLE_USER')->edit(Request::create('/admin/fournisseurs/nouveau'));
    }
}
