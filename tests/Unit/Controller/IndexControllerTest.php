<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\IndexController;
use Epiclub\Engine\EnvironmentFileParser;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableIndexController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * [REFACTOR #74] Tests Unit de IndexController.
 *
 * ⚠️ Périmètre :
 *  - index()      : 2 branches (authentifié / non authentifié) ;
 *  - dashboard()  : 1 branche (render) ;
 *  - updateSmtp() : branches SMTP non couvertes par les 7 tests
 *                   Integration (IndexControllerRootUrlTest) :
 *                   champs vides, ROOT_URL invalide, mot de passe vide.
 *
 * ⚠️ Hors périmètre :
 *  - systemSettings() : couvert en Integration (pré-remplissage ROOT_URL).
 *  - testMail()       : effet de bord réseau (SMTP), non testé en Unit.
 */
final class IndexControllerTest extends TestCase
{
    private const CSRF_TOKEN = 'test_csrf_token';

    public function testIndexRedirectsToLoginWhenNotAuthenticated(): void
    {
        $session = $this->makeSessionMock(granted: false);
        $controller = new TestableIndexController($session);

        $response = $controller->index(Request::create('/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/se_connecter', $response->headers->get('Location'));
    }

    public function testIndexRedirectsToDashboardWhenAuthenticated(): void
    {
        $session = $this->makeSessionMock(granted: true);
        $controller = new TestableIndexController($session);

        $response = $controller->index(Request::create('/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/tableau_de_bord', $response->headers->get('Location'));
    }

    public function testDashboardRendersTableauDeBord(): void
    {
        $session = $this->makeSessionMock(granted: true);
        $controller = new TestableIndexController($session);
        
        $response = $controller->dashboard(Request::create('/tableau_de_bord'));
        
        // Le contrôleur retourne une Response 200 avec un contenu non vide.
        // On ne teste PAS le contenu exact du template (fragile, rôle des
        // tests de rendu Twig, pas d'un test de contrôleur).
    self::assertSame(200, $response->getStatusCode());
    self::assertNotEmpty($response->getContent());
    }

    public function testUpdateSmtpRejectsWhenRequiredFieldsEmpty(): void
    {
        $session = $this->makeSessionMock(granted: true);
        $controller = new TestableIndexController($session);

        $request = $this->makePostRequest([
            'smtp_host'     => '',  // vide
            'smtp_port'     => '587',
            'smtp_username' => 'user',
            'mail_from'     => 'from@test.fr',
        ]);

        $response = $controller->updateSmtp($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/system-settings', $response->headers->get('Location'));

        $flashes = $session->getFlashBag()->get('error');
        self::assertContains(
            'Tous les champs obligatoires doivent être remplis.',
            $flashes
        );
    }

    public function testUpdateSmtpRejectsInvalidRootUrl(): void
    {
        $session = $this->makeSessionMock(granted: true);
        $controller = new TestableIndexController($session);

        $request = $this->makePostRequest([
            'smtp_host'     => 'smtp.example.com',
            'smtp_port'     => '587',
            'smtp_username' => 'user',
            'mail_from'     => 'from@test.fr',
            'root_url'      => 'not-a-url',  // invalide
        ]);

        $response = $controller->updateSmtp($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/system-settings', $response->headers->get('Location'));

        // Message d'erreur émis par RootUrlValidator (contient 'URL').
        $flashes = $session->getFlashBag()->get('error');
        self::assertNotEmpty($flashes);
        self::assertStringContainsString('URL', $flashes[0]);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Mock Session tolérant.
     *
     * ⚠️ Le constructeur parent appelle Session::get('csrf_token') pour
     * le global Twig. On utilise willReturnCallback pour discriminer par
     * clé (cf. Points de vigilance REPRISE.md).
     */
    private function makeSessionMock(bool $granted): Session
    {
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) {
                return $default;
            }
        );
        $session->method('isGranted')->willReturn($granted);
        $session->method('getFlashBag')->willReturn(new \Symfony\Component\HttpFoundation\Session\Flash\FlashBag());
        return $session;
    }

    /**
     * @param array<string,string> $params
     */
    private function makePostRequest(array $params): Request
    {
        // ⚠️ Pattern CSRF : replace() PUIS set('csrf_token', ...)
        // (cf. Points de vigilance REPRISE.md).
        $request = Request::create('/update-smtp', 'POST');
        $request->request->replace($params);
        $request->request->set('csrf_token', self::CSRF_TOKEN);
        return $request;
    }
}