<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\JournalManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableJournalController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [#100 — Dette 15 soldée]
 *
 * JournalController::index() lisait directement `$_GET` et n'acceptait
 * pas de `Request` en signature — non testable en Unit et incohérent
 * avec le dispatcher qui passe TOUJOURS le Request
 * (call_user_func_array([$controller, $action], [$request])).
 *
 * ⚠️ Garde d'accès : le code lit `if (!isGranted) redirectTo(/se_connecter)`
 * est CONSERVÉ tel quel (iso-comportement). La redirection anonyme vers
 * la page de connexion est voulue et partagée avec voir()/pdf() et
 * IndexController::index() ; deniAccessUnlessGranted renverrait vers /
 * avec un flash, régression UX sans gain de sécurité.
 */
final class JournalControllerIndexTest extends TestCase
{
    public function testIndexReadsFiltersFromRequestQuery(): void
    {
        $capturedFiltres = null;
        $journalManager = $this->makeJournalManager($capturedFiltres);

        $controller = new TestableJournalController($this->mockSession(true), $journalManager);

        $request = Request::create(
            '/journaux?controleur=5&annee=2026&order_by=u.nom&order_dir=asc&limit=25&page=3',
            'GET'
        );

        $response = $controller->index($request);

        self::assertInstanceOf(Response::class, $response);
        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame([
            'controleur_id' => '5',
            'annee'         => '2026',
            'order_by'      => 'u.nom',
            'order_dir'     => 'asc',
            'limit'         => 25,
            'page'          => 3,
        ], $capturedFiltres);
    }

    public function testIndexAppliesDefaultsWhenNoQueryParams(): void
    {
        $capturedFiltres = null;
        $journalManager = $this->makeJournalManager($capturedFiltres);

        $controller = new TestableJournalController($this->mockSession(true), $journalManager);

        $response = $controller->index(Request::create('/journaux', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'controleur_id' => null,
            'annee'         => null,
            'order_by'      => 'date_debut',
            'order_dir'     => 'desc',
            'limit'         => 10,
            'page'          => 1,
        ], $capturedFiltres);
    }

    public function testIndexRedirectsAnonymousToLogin(): void
    {
        $capturedFiltres = null;
        $journalManager = $this->makeJournalManager($capturedFiltres, false);

        $controller = new TestableJournalController($this->mockSession(false), $journalManager);

        $response = $controller->index(Request::create('/journaux', 'GET'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/se_connecter', $response->getTargetUrl());
        // Le manager ne doit jamais être sollicité pour un anonyme.
        self::assertNull($capturedFiltres);
    }

    private function makeJournalManager(?array &$capturedFiltres, bool $expectsCall = true): JournalManager
    {
        $journalManager = $this->getMockBuilder(JournalManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $journalManager->expects($expectsCall ? self::once() : self::never())
            ->method('getControlesClotures')
            ->willReturnCallback(function (array $filtres) use (&$capturedFiltres) {
                $capturedFiltres = $filtres;
                return ['data' => [], 'page' => $filtres['page'], 'totalPages' => 1, 'total' => 0];
            });
        $journalManager->method('getControleurs')->willReturn([]);
        $journalManager->method('getAnneesDisponibles')->willReturn([]);

        return $journalManager;
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
