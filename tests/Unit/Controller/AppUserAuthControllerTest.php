<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\AppUserAuthenticator;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAppUserAuthController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [#102 — Dette 21 soldée]
 *
 * AppUserAuthController envoyait la réponse via `$response->send()` sans
 * la retourner :
 *  - login() : send() puis continuation du code (render du formulaire de
 *    login envoyé EN PLUS de la redirection — double sortie, warnings
 *    « headers already sent ») ;
 *  - logout() : send() puis retour implicite null → le dispatcher
 *    appelait send() sur null → Error fatale (déconnexion « marchait »
 *    uniquement parce que le header Location partait avant le crash).
 * Les actions retournent désormais leur Response (pattern #95) et
 * l'Authenticator est injecté via la factory protected authenticator()
 * (pattern Vague 11 — plus de new en dur, mockable).
 */
final class AppUserAuthControllerTest extends TestCase
{
    private const CSRF = 'csrf-token-fixe';

    public function testLoginGetRendersLoginForm(): void
    {
        $controller = new TestableAppUserAuthController($this->mockSession());

        $response = $controller->login(Request::create('/se_connecter', 'GET'));

        self::assertInstanceOf(Response::class, $response);
        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testLoginPostSuccessReturnsRedirect(): void
    {
        $authenticator = $this->makeAuthenticator();
        $authenticator->expects(self::once())
            ->method('authenticate')
            ->with('jean', 'secret')
            ->willReturn(true);

        // [SÉCURITÉ] Le token CSRF est régénéré après login (anti-fixation).
        $session = $this->mockSession();
        $session->expects(self::atLeastOnce())->method('set');

        $controller = new TestableAppUserAuthController($session, $authenticator);

        $request = Request::create('/se_connecter', 'POST', [
            'csrf_token' => self::CSRF,
            'username'   => 'jean',
            'password'   => 'secret',
        ]);

        $response = $controller->login($request);

        // Fix de bug documenté : avant #102, le code continuait après
        // send() et retournait le render du formulaire (double sortie).
        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testLoginPostFailureRendersFormWithError(): void
    {
        $authenticator = $this->makeAuthenticator();
        $authenticator->method('authenticate')->willReturn(false);
        $authenticator->method('getError')->willReturn('Identifiants incorrects');

        $controller = new TestableAppUserAuthController($this->mockSession(), $authenticator);

        $request = Request::create('/se_connecter', 'POST', [
            'csrf_token' => self::CSRF,
            'username'   => 'jean',
            'password'   => 'mauvais',
        ]);

        $response = $controller->login($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('Identifiants incorrects', $response->getContent());
    }

    public function testLoginPostInvalidCsrfDoesNotCallAuthenticator(): void
    {
        $authenticator = $this->makeAuthenticator();
        $authenticator->expects(self::never())->method('authenticate');

        $controller = new TestableAppUserAuthController($this->mockSession(), $authenticator);

        $request = Request::create('/se_connecter', 'POST', [
            'csrf_token' => 'mauvais-token',
            'username'   => 'jean',
            'password'   => 'secret',
        ]);

        $response = $controller->login($request);

        self::assertStringContainsString('Token CSRF invalide', $response->getContent());
    }

    public function testLogoutReturnsRedirectAndClearsSession(): void
    {
        $session = $this->mockSession();
        $session->expects(self::once())->method('clear');

        $controller = new TestableAppUserAuthController($session);

        $response = $controller->logout(Request::create('/se_deconnecter', 'POST', [
            'csrf_token' => self::CSRF,
        ]));

        // Fix de bug documenté : avant #102, logout() retournait null →
        // send() sur null dans le dispatcher (Error fatale après envoi
        // du header).
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->getTargetUrl());
    }

    private function makeAuthenticator(): AppUserAuthenticator
    {
        return $this->getMockBuilder(AppUserAuthenticator::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function mockSession(): Session
    {
        $session = $this->getMockBuilder(Session::class)->getMock();
        $session->method('get')->willReturnCallback(
            fn(string $key) => $key === 'csrf_token' ? self::CSRF : null
        );
        $session->method('has')->willReturnCallback(
            fn(string $key) => $key === 'csrf_token'
        );
        $session->method('isAuthenticated')->willReturn(false);
        $session->method('isGranted')->willReturn(false);
        return $session;
    }
}
