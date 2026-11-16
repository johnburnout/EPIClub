<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\FiltresListe;
use Epiclub\Engine\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-11-15] Filtres d'une liste conservés après la consultation, la
 * modification ou le contrôle d'une fiche, au retour vers la liste.
 */
final class FiltresListeTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
    }

    /** @return array<string, array{string, bool}> */
    public static function chemins(): array
    {
        return [
            'équipements'          => ['/equipements', true],
            'contrôles'            => ['/admin/controles', true],
            'fiche contrôle'       => ['/admin/controles/edit/12', true],
            'acquisitions'         => ['/admin/acquisitions', true],
            'prêts'                => ['/prets', true],
            'fiche équipement'     => ['/equipements/equipement-3', false],
            'fiche contrôle id 0'  => ['/admin/controles/edit/0', false],
            'ligne de contrôle'    => ['/admin/controles/update-ligne/4', false],
            'liste avec requête'   => ['/equipements?statut=1', false],
        ];
    }

    #[DataProvider('chemins')]
    public function testListesConcernees(string $chemin, bool $attendu): void
    {
        self::assertSame($attendu, FiltresListe::estListe($chemin));
    }

    public function testLesFiltresAffichesSontReprisAuRetour(): void
    {
        $this->afficher('/equipements?recherche=corde&statut=1&page=2');

        self::assertSame('/equipements?recherche=corde&statut=1&page=2', FiltresListe::url($this->session, '/equipements'));
    }

    public function testChaqueListeASesFiltres(): void
    {
        $this->afficher('/equipements?recherche=corde');
        $this->afficher('/admin/controles/edit/7?lignes_statut=a_controler');

        self::assertSame('/equipements?recherche=corde', FiltresListe::url($this->session, '/equipements'));
        self::assertSame('/admin/controles/edit/7?lignes_statut=a_controler', FiltresListe::url($this->session, '/admin/controles/edit/7'));
        self::assertSame('/admin/controles/edit/8', FiltresListe::url($this->session, '/admin/controles/edit/8'));
        self::assertSame('/prets', FiltresListe::url($this->session, '/prets'));
    }

    public function testLaListeSansParametreEffaceLesFiltres(): void
    {
        $this->afficher('/equipements?recherche=corde');
        $this->afficher('/equipements');

        self::assertSame('/equipements', FiltresListe::url($this->session, '/equipements'));
    }

    public function testUneUrlAutreQueLaListeEstRendueTelleQuelle(): void
    {
        $this->afficher('/equipements?recherche=corde');

        self::assertSame('/equipements/equipement-3', FiltresListe::url($this->session, '/equipements/equipement-3'));
        self::assertSame('/equipements?statut=2', FiltresListe::url($this->session, '/equipements?statut=2'));
    }

    public function testUnExportNeModifiePasLesFiltres(): void
    {
        $this->afficher('/equipements?recherche=corde');
        $this->afficher('/equipements?recherche=sangle&action=export&export_type=pdf');

        self::assertSame('/equipements?recherche=corde', FiltresListe::url($this->session, '/equipements'));
    }

    /**
     * Bug de la v3.1.0 : le bouton « Appliquer » de la liste des
     * équipements envoie action=filter ; ces filtres n'étaient pas
     * mémorisés (toute requête avec « action » était prise pour un export).
     */
    public function testLesFiltresDuBoutonAppliquerSontMemorises(): void
    {
        $this->afficher('/equipements?q=corde&categorie=3&order_by=categorie&action=filter&export_type=pdf');

        self::assertSame('/equipements?q=corde&categorie=3&order_by=categorie', FiltresListe::url($this->session, '/equipements'));
    }

    public function testLesAnciensExportsDirectsNeModifientPasLesFiltres(): void
    {
        $this->afficher('/equipements?q=corde');
        $this->afficher('/equipements?q=sangle&action=excel');
        $this->afficher('/equipements?q=baudrier&action=pdf');

        self::assertSame('/equipements?q=corde', FiltresListe::url($this->session, '/equipements'));
    }

    public function testSeulsLesAffichagesReussisSontMemorises(): void
    {
        $this->afficher('/equipements?recherche=corde');
        FiltresListe::memoriser($this->session, Request::create('/equipements?recherche=x', 'POST'), new Response());
        FiltresListe::memoriser($this->session, Request::create('/equipements?recherche=y'), new RedirectResponse('/'));
        FiltresListe::memoriser($this->session, Request::create('/equipements/equipement-3?recherche=z'), new Response());

        self::assertSame('/equipements?recherche=corde', FiltresListe::url($this->session, '/equipements'));
        self::assertSame(['/equipements' => 'recherche=corde'], $this->session->get(FiltresListe::CLE_SESSION));
    }

    public function testLesListesLesPlusAnciennesSontOubliees(): void
    {
        for ($id = 1; $id <= FiltresListe::MAX_LISTES + 1; $id++) {
            $this->afficher("/admin/controles/edit/{$id}?lignes_search=a");
        }

        self::assertCount(FiltresListe::MAX_LISTES, $this->session->get(FiltresListe::CLE_SESSION));
        self::assertSame('/admin/controles/edit/1', FiltresListe::url($this->session, '/admin/controles/edit/1'));
        self::assertSame('/admin/controles/edit/2?lignes_search=a', FiltresListe::url($this->session, '/admin/controles/edit/2'));
    }

    public function testUneSessionAltereeNeBloquePas(): void
    {
        $this->session->set(FiltresListe::CLE_SESSION, 'corrompu');

        self::assertSame('/equipements', FiltresListe::url($this->session, '/equipements'));
        $this->afficher('/equipements?recherche=corde');
        self::assertSame('/equipements?recherche=corde', FiltresListe::url($this->session, '/equipements'));
    }

    private function afficher(string $url): void
    {
        FiltresListe::memoriser($this->session, Request::create($url), new Response('liste'));
    }
}
