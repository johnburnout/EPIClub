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

    private function mockSession(bool $granted): Session
    {
        $session = $this->getMockBuilder(Session::class)->getMock();
        $session->method('get')->willReturn(null);
        $session->method('isAuthenticated')->willReturn($granted);
        $session->method('isGranted')->willReturn($granted);
        return $session;
    }
}
