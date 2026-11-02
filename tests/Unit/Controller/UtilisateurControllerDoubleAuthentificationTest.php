<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableUtilisateurController;
use Epiclub\Tests\Unit\Domain\Support\InMemoryDoubleAuthentificationManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [migration 020] Réinitialisation de la double authentification d'un
 * autre compte (appareil perdu) depuis sa fiche.
 */
final class UtilisateurControllerDoubleAuthentificationTest extends TestCase
{
    private InMemoryDoubleAuthentificationManager $doubleAuth;

    protected function setUp(): void
    {
        $this->doubleAuth = new InMemoryDoubleAuthentificationManager();
        $this->doubleAuth->activerTotp(5, 'secret', 1);
        $this->doubleAuth->ajouterPasskey(5, 'cred', 'pem', 0, 'Clé');
        $this->doubleAuth->genererCodesSecours(5);
    }

    private function reinitialiser(string $roleActeur, string $roleCible, int $acteurId = 2): \Symfony\Component\HttpFoundation\Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => $acteurId, 'username' => 'a', 'role' => $roleActeur]);
        $session->set('csrf_token', 'csrf');
        $manager = $this->createMock(UtilisateurManager::class);
        $manager->method('findId')->willReturn(['id' => 5, 'username' => 'cible', 'role' => $roleCible, 'est_protege' => 0]);
        $c = new TestableUtilisateurController($session, $manager);
        $c->doubleAuth = $this->doubleAuth;

        $request = Request::create('/admin/utilisateurs/utilisateur_2fa_reinitialiser-5', 'POST', ['csrf_token' => 'csrf']);
        $request->attributes->set('id', '5');
        return $c->reinitialiserDoubleAuthentification($request);
    }

    public function testUnAdministrateurReinitialiseUnUtilisateur(): void
    {
        $reponse = $this->reinitialiser('ROLE_ADMIN', 'ROLE_USER');

        self::assertSame('/admin/utilisateurs/utilisateur-5', $reponse->getTargetUrl());
        self::assertFalse($this->doubleAuth->estConfiguree(5));
        self::assertSame([], $this->doubleAuth->etat(5)['codes_secours']);
    }

    public function testUnAdministrateurNeReinitialisePasUnAutreAdministrateur(): void
    {
        $this->reinitialiser('ROLE_ADMIN', 'ROLE_ADMIN');

        self::assertTrue($this->doubleAuth->estConfiguree(5));
    }

    public function testUnSuperAdministrateurReinitialiseUnAdministrateur(): void
    {
        $this->reinitialiser('ROLE_SUPER_ADMIN', 'ROLE_ADMIN');

        self::assertFalse($this->doubleAuth->estConfiguree(5));
    }

    public function testPasSurSonPropreCompte(): void
    {
        $reponse = $this->reinitialiser('ROLE_SUPER_ADMIN', 'ROLE_SUPER_ADMIN', 5);

        self::assertSame('/mon-compte/securite', $reponse->getTargetUrl());
        self::assertTrue($this->doubleAuth->estConfiguree(5));
    }

    public function testRefuseSousAdministrateur(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->reinitialiser('ROLE_CONTROLLEUR', 'ROLE_USER');
    }
}
