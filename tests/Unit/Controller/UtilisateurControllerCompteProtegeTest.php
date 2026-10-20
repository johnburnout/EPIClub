<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableUtilisateurController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-20] Compte super-administrateur protégé (créé par le setup) :
 * ni suppression, ni rétrogradation ; email et mot de passe modifiables
 * par son seul titulaire.
 */
final class UtilisateurControllerCompteProtegeTest extends TestCase
{
    private Session $session;
    /** @var UtilisateurManager&MockObject */
    private UtilisateurManager $manager;

    private const PROTEGE = [
        'id' => 1, 'nom' => 'Roussie', 'prenom' => 'Jean', 'username' => 'jean',
        'email' => 'jean@club.fr', 'role' => 'ROLE_SUPER_ADMIN', 'est_protege' => 1,
    ];

    protected function setUp(): void
    {
        $this->manager = $this->createMock(UtilisateurManager::class);
        $this->manager->method('findId')->willReturn(self::PROTEGE);
        $this->manager->method('findAll')->willReturn([
            self::PROTEGE,
            ['id' => 2, 'role' => 'ROLE_SUPER_ADMIN', 'est_protege' => 0],
        ]);
        $this->connecter(2);
    }

    private function connecter(int $id): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => $id, 'username' => 'u' . $id, 'role' => 'ROLE_SUPER_ADMIN']);
        $this->session->set('csrf_token', 'csrf');
    }

    private function controller(): TestableUtilisateurController
    {
        return new TestableUtilisateurController($this->session, $this->manager);
    }

    private function modifier(array $post): Response
    {
        $request = Request::create('/admin/utilisateurs/utilisateur-1', 'POST', $post + ['csrf_token' => 'csrf']);
        $request->attributes->set('id', '1');
        return $this->controller()->show($request);
    }

    public function testUnAutreSuperAdminNePeutPasLeSupprimer(): void
    {
        $this->manager->expects(self::never())->method('delete');

        $request = Request::create('/admin/utilisateurs/utilisateur_supprimer-1', 'POST', ['csrf_token' => 'csrf']);
        $request->attributes->set('id', '1');
        $this->controller()->delete($request);

        self::assertStringContainsString('protégé', $this->session->getFlashBag()->get('error')[0]);
    }

    public function testUnAutreSuperAdminNePeutPasLeRetrograderNiChangerEmailEtMotDePasse(): void
    {
        $this->manager->expects(self::never())->method('updateRole');
        $this->manager->expects(self::never())->method('changePassword');
        $enregistre = null;
        $this->manager->method('save')->willReturnCallback(function (array $u) use (&$enregistre) {
            $enregistre = $u;
            return true;
        });

        $this->modifier([
            'nom' => 'Roussie', 'prenom' => 'Jean', 'username' => 'pirate',
            'email' => 'pirate@exemple.fr', 'password' => 'NouveauMotDePasse!2026', 'role' => 'ROLE_USER',
        ]);

        self::assertSame('jean@club.fr', $enregistre['email']);
        self::assertSame('jean', $enregistre['username']);
    }

    public function testLeTitulaireModifieSonEmailEtSonMotDePasse(): void
    {
        $this->connecter(1);
        $this->manager->expects(self::once())->method('changePassword');
        $this->manager->expects(self::never())->method('updateRole');
        $enregistre = null;
        $this->manager->method('save')->willReturnCallback(function (array $u) use (&$enregistre) {
            $enregistre = $u;
            return true;
        });

        $this->modifier([
            'nom' => 'Roussie', 'prenom' => 'Jean', 'email' => 'jean.nouveau@club.fr',
            'password' => 'NouveauMotDePasse!2026', 'role' => 'ROLE_SUPER_ADMIN',
        ]);

        self::assertSame('jean.nouveau@club.fr', $enregistre['email']);
    }

    public function testFicheAfficheLaProtection(): void
    {
        $request = Request::create('/admin/utilisateurs/utilisateur-1');
        $request->attributes->set('id', '1');
        $html = (string) $this->controller()->show($request)->getContent();

        self::assertStringContainsString('🔒 Compte protégé', $html);
        self::assertStringNotContainsString('name="password"', $html);
        self::assertStringNotContainsString('delete-utilisateur-form', $html);
    }

    public function testUnCompteNonProtegeResteSupprimable(): void
    {
        $this->manager = $this->createMock(UtilisateurManager::class);
        $this->manager->method('findId')->willReturn(['id' => 3, 'nom' => 'X', 'prenom' => 'Y', 'username' => 'admin', 'role' => 'ROLE_USER', 'est_protege' => 0]);
        $this->manager->method('hasAcquisitionsSaisies')->willReturn(false);
        $this->manager->expects(self::once())->method('delete')->with(3);

        $request = Request::create('/admin/utilisateurs/utilisateur_supprimer-3', 'POST', ['csrf_token' => 'csrf']);
        $request->attributes->set('id', '3');
        $this->controller()->delete($request);
    }

    public function testListeMarqueLeCompteProtegeEtDesactiveLesSuppressionsImpossibles(): void
    {
        $this->manager = $this->createMock(UtilisateurManager::class);
        $this->manager->method('findAll')->willReturn([
            self::PROTEGE,
            ['id' => 2, 'nom' => 'Moi', 'prenom' => 'M', 'username' => 'moi', 'email' => 'm@c.fr', 'role' => 'ROLE_SUPER_ADMIN', 'est_protege' => 0],
            ['id' => 3, 'nom' => 'Autre', 'prenom' => 'A', 'username' => 'autre', 'email' => 'a@c.fr', 'role' => 'ROLE_USER', 'est_protege' => 0],
        ]);

        $html = (string) $this->controller()->list(Request::create('/admin/utilisateurs'))->getContent();

        self::assertStringContainsString('jean <span class="badge bg-dark" title="Compte protégé', $html);
        self::assertStringContainsString('title="Compte protégé (créé à l&#039;installation)."', $html);
        self::assertStringContainsString('title="Vous ne pouvez pas supprimer votre propre compte."', $html);
        self::assertStringContainsString('action="/admin/utilisateurs/utilisateur_supprimer-3"', $html);
        self::assertStringNotContainsString('utilisateur_supprimer-1"', $html);
        self::assertStringNotContainsString('utilisateur_supprimer-2"', $html);
    }
}
