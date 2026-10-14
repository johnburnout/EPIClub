<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAppUserRegisterController;
use Epiclub\Tests\Unit\Controller\Support\TestableAppUserRegisterControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
* [REFACTOR #76] Tests Unit des méthodes GET d'AppUserRegisterController.
*
* Périmètre : methods qui n'ont pas été couvertes par
* AppUserRegisterControllerHandlersTest (qui couvre les POST) :
*  - forgotPassword()       : branche GET uniquement (POST couvert ailleurs) ;
*  - forgotPasswordConfirm(): GET trivial ;
*  - resetPassword()        : branche GET avec token valide uniquement
*                             (token manquant/invalide/expiré déjà couverts).
*
* ⚠️ Les POST (validateCsrf, envoi d'email, save) sont couverts par
* AppUserRegisterControllerHandlersTest — pas de doublon ici.
*
* ⚠️ On n'asserte PAS sur le contenu HTML exact (fragile, cf. REPRISE.md).
* Seulement : statut HTTP + flash + contenu non vide.
*/
    
final class AppUserRegisterControllerTest extends TestCase
{

    // ==================================================================
    // forgotPassword() — GET uniquement
    // ==================================================================

    public function testForgotPasswordGetRendersFormWithNoErrors(): void
    {
        $session = $this->makeSession(role: null);
        $controller = $this->makeControllerWith(session: $session);

        $response = $controller->forgotPassword(Request::create('/mot_de_passe_oublie', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->getContent());
    }

    // ==================================================================
    // forgotPasswordConfirm()
    // ==================================================================

    public function testForgotPasswordConfirmRendersPage(): void
    {
        $session = $this->makeSession(role: null);
        $controller = $this->makeControllerWith(session: $session);

        $response = $controller->forgotPasswordConfirm(
            Request::create('/mot_de_passe_oublie/confirmation', 'GET')
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->getContent());
    }

    // ==================================================================
    // resetPassword() — GET token valide
    // ==================================================================

    public function testResetPasswordGetRendersFormWhenTokenValid(): void
    {
        $user = [
            'id' => 42,
            'email' => 'user@example.com',
            'reset_token' => 'valid-token',
            'reset_token_expires' => (new \DateTime('+1 hour'))->format('Y-m-d H:i:s'),
        ];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);

        $session = $this->makeSession(role: null);
        $controller = $this->makeControllerWith(
            session: $session,
            utilisateurManager: $utilisateurManager,
        );

        $request = Request::create(
            '/regenerer_mot_de_passe',
            'GET',
            ['token' => 'valid-token']
        );

        $response = $controller->resetPassword($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->getContent());
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeControllerWith(
        ?Session $session = null,
        ?UtilisateurManager $utilisateurManager = null,
    ): TestableAppUserRegisterController {
        $session ??= $this->makeSession(role: 'ROLE_USER');

        $builder = (new TestableAppUserRegisterControllerBuilder($this))
            ->withSession($session);

        if ($utilisateurManager !== null) {
            $builder->withUtilisateurManager($utilisateurManager);
        }

        return $builder->build();
    }

    /**
     * Crée une session avec ou sans utilisateur.
     *
     * `role = null` → aucun user en session → isGranted() retourne false.
     * `role = 'ROLE_USER'` → user id=1 avec role USER.
     */
    private function makeSession(?string $role): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('csrf_token', 'test-csrf-token');

        if ($role !== null) {
            $session->set('user', [
                'id' => 1,
                'username' => 'test',
                'role' => $role,
            ]);
        }

        return $session;
    }
}