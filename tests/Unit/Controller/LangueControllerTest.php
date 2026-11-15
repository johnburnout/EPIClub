<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableLangueController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [Localisation] POST /langue : choix de la langue d'affichage,
 * mémorisé en session et, pour un utilisateur connecté, en base.
 */
final class LangueControllerTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('csrf_token', 'jeton');
    }

    private function requete(array $post, array $serveur = []): Request
    {
        return Request::create('/langue', 'POST', $post, [], [], $serveur + ['HTTP_HOST' => 'club.test']);
    }

    public function testVisiteurAnonymeChoixEnSession(): void
    {
        $controleur = new TestableLangueController($this->session);
        $reponse = $controleur->choisir($this->requete(['csrf_token' => 'jeton', 'langue' => 'es']));

        self::assertSame('es', $this->session->get('langue'));
        self::assertSame([], $controleur->enregistrements);
        self::assertSame('/', $reponse->headers->get('Location'));
    }

    public function testUtilisateurConnecteChoixEnregistre(): void
    {
        $this->session->set('user', ['id' => 7, 'nom' => 'X', 'langue' => null]);
        $controleur = new TestableLangueController($this->session);
        $controleur->choisir($this->requete(['csrf_token' => 'jeton', 'langue' => 'it']));

        self::assertSame([[7, 'it']], $controleur->enregistrements);
        self::assertSame('it', $this->session->get('user')['langue']);
        self::assertSame('it', $this->session->get('langue'));
    }

    public function testLangueInconnueIgnoree(): void
    {
        $this->session->set('user', ['id' => 7]);
        $controleur = new TestableLangueController($this->session);
        $controleur->choisir($this->requete(['csrf_token' => 'jeton', 'langue' => 'de']));

        self::assertNull($this->session->get('langue'));
        self::assertSame([], $controleur->enregistrements);
    }

    public function testJetonCsrfExige(): void
    {
        $controleur = new TestableLangueController($this->session);

        $this->expectException(\Throwable::class);
        $controleur->choisir($this->requete(['csrf_token' => 'mauvais', 'langue' => 'en']));
    }

    public function testRetourALaPageDOrigineDuMemeSite(): void
    {
        $controleur = new TestableLangueController($this->session);
        $reponse = $controleur->choisir($this->requete(
            ['csrf_token' => 'jeton', 'langue' => 'en'],
            ['HTTP_REFERER' => 'http://club.test/equipements?page=2']
        ));

        self::assertSame('/equipements?page=2', $reponse->headers->get('Location'));
    }

    public function testPasDeRedirectionVersUnAutreSite(): void
    {
        $controleur = new TestableLangueController($this->session);
        foreach (['https://evil.test/x', '//evil.test/x', 'javascript:alert(1)'] as $referer) {
            $reponse = $controleur->choisir($this->requete(
                ['csrf_token' => 'jeton', 'langue' => 'en'],
                ['HTTP_REFERER' => $referer]
            ));
            self::assertSame('/', $reponse->headers->get('Location'), $referer);
        }
    }

    public function testEchecDEnregistrementNeBloquePas(): void
    {
        // Migration 022 pas encore appliquée : le choix vaut pour la session.
        $this->session->set('user', ['id' => 7]);
        $controleur = new TestableLangueController($this->session);
        $controleur->echec = true;
        $reponse = $controleur->choisir($this->requete(['csrf_token' => 'jeton', 'langue' => 'en']));

        self::assertSame(302, $reponse->getStatusCode());
        self::assertSame('en', $this->session->get('langue'));
        self::assertSame('en', $this->session->get('user')['langue']);
    }
}
