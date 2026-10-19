<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableGuideController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [#104 — Dette 22 soldée]
 *
 * Décision produit 2026-10-17 : le guide utilisateur (/guide) est
 * réservé aux utilisateurs connectés. Il était jusqu'ici la seule
 * ressource documentaire publique (testée comme telle dans
 * AccessControlTest).
 */
final class GuideControllerTest extends TestCase
{
    public function testIndexDeniesAccessWhenUserIsNotGranted(): void
    {
        $session = $this->mockSession(false);

        $controller = new TestableGuideController($session);

        $this->expectException(AccessDeniedException::class);
        $controller->index(Request::create('/guide', 'GET'));
    }

    public function testIndexRendersGuideForGrantedUser(): void
    {
        $session = $this->mockSession(true);

        $controller = new TestableGuideController($session);

        $response = $controller->index(Request::create('/guide', 'GET'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        // Le rendu passe Parsedown sur docs/guide_utilisateur.md :
        // le HTML produit doit être non vide.
        self::assertNotSame('', trim($response->getContent()));
    }

    public function testGuideEchappeHtmlBrut(): void
    {
        // [SÉCURITÉ — audit C12] Un Markdown contenant du HTML brut
        // (<script>, attributs on*) doit être échappé par le safe mode
        // de Parsedown : jamais de XSS stockée via le guide.
        $mdFile = tempnam(sys_get_temp_dir(), 'guide_test_');
        file_put_contents(
            $mdFile,
            "# Guide\n\n<script>alert('xss')</script>\n\n<img src=x onerror=alert(1)>\n"
        );

        $session = $this->mockSession(true);
        $controller = new TestableGuideController($session);
        $controller->setGuideFilePath($mdFile);

        try {
            $response = $controller->index(Request::create('/guide', 'GET'));
        } finally {
            unlink($mdFile);
        }

        $content = $response->getContent();
        // Aucune balise HTML active injectée ne doit subsister : le HTML
        // brut du Markdown doit être échappé (visible en texte). Nota :
        // le layout contient ses propres <script> légitimes — on cible
        // donc uniquement le contenu malveillant injecté.
        self::assertStringNotContainsString("<script>alert('xss')</script>", $content);
        self::assertStringNotContainsString('<img src=x', $content);
        self::assertStringContainsString('&lt;script&gt;', $content);
    }

    private function mockSession(bool $granted): Session
    {
        $session = $this->getMockBuilder(Session::class)->getMock();
        $session->method('get')->willReturn(null);
        $session->method('isAuthenticated')->willReturn($granted);
        $session->method('isGranted')->willReturn($granted);
        return $session;
    }
}
