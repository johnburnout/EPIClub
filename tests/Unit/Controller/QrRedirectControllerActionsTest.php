<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\QrRedirectController;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableQrRedirectController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * [REFACTOR #95 - Dette 13] Tests Unit des ACTIONS publiques de
 * QrRedirectController — impossibles avant #95 (header()+exit tuait
 * le processus PHP). Les actions retournent désormais des objets
 * Response / RedirectResponse / JsonResponse.
 *
 * Chemins couverts : redirect() (id absent, équipement inconnu + flash,
 * utilisateur non connecté, sans contrôle, contrôle avec ligne
 * existante, contrôle avec ligne créée), saveQr()/apiGenerateQr()/
 * generateQr() (400/404, avant tout appel à getBaseUrl()).
 */
final class QrRedirectControllerActionsTest extends TestCase
{
    // ==================================================================
    // redirect()
    // ==================================================================

    public function testRedirectWithoutIdGoesToEquipements(): void
    {
        $controller = $this->makeController(user: ['id' => 1]);

        $response = $controller->redirect(Request::create('/qr/'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/equipements', $response->getTargetUrl());
    }

    public function testRedirectWithUnknownEquipementFlashesAndRedirects(): void
    {
        $flashBag = new FlashBag();
        $equipementManager = $this->makeEquipementManager(null);
        $controller = $this->makeController(['id' => 1], $flashBag, $equipementManager);

        $response = $controller->redirect($this->makeRequest('/qr/999', 999));

        self::assertSame('/equipements', $response->getTargetUrl());
        self::assertSame(['Équipement non trouvé'], $flashBag->peek('danger'));
    }

    public function testRedirectWithoutUserGoesToLogin(): void
    {
        $equipementManager = $this->makeEquipementManager(['id' => 5, 'reference' => 'REF']);
        // isGranted('ROLE_USER') true (l'accès est accordé) mais la
        // session n'a pas d'utilisateur : cas défensif historique.
        $controller = $this->makeController(null, null, $equipementManager);

        $response = $controller->redirect($this->makeRequest('/qr/5', 5));

        self::assertSame('/se_connecter?redirect=/qr/5', $response->getTargetUrl());
    }

    public function testRedirectWithoutActiveControleGoesToEquipementCard(): void
    {
        $equipementManager = $this->makeEquipementManager(['id' => 5, 'reference' => 'REF']);
        $controleManager = $this->createMock(ControleManager::class);
        $controleManager->method('findActiveByUser')->willReturn(null);
        $controller = $this->makeController(['id' => 1], null, $equipementManager, $controleManager);

        $response = $controller->redirect($this->makeRequest('/qr/5', 5));

        self::assertSame('/equipements/equipement-5', $response->getTargetUrl());
    }

    public function testRedirectWithExistingLigneGoesToUpdateLigne(): void
    {
        $equipementManager = $this->makeEquipementManager(['id' => 5, 'reference' => 'REF']);
        $controleManager = $this->createMock(ControleManager::class);
        $controleManager->method('findActiveByUser')->willReturn(['id' => 7, 'statut' => 'ouvert']);
        $ligneManager = $this->createMock(ControleLigneManager::class);
        $ligneManager->method('findByControleAndEquipement')->willReturn(['id' => 99]);
        $controller = $this->makeController(['id' => 1], null, $equipementManager, $controleManager, $ligneManager);

        $response = $controller->redirect($this->makeRequest('/qr/5', 5));

        self::assertSame('/admin/controles/update-ligne/99', $response->getTargetUrl());
    }

    public function testRedirectAddsEquipementThenGoesToNewLigne(): void
    {
        $equipementManager = $this->makeEquipementManager(['id' => 5, 'reference' => 'REF']);
        $controleManager = $this->createMock(ControleManager::class);
        $controleManager->method('findActiveByUser')->willReturn(['id' => 7, 'statut' => 'ouvert']);
        $ligneManager = $this->createMock(ControleLigneManager::class);
        // 1er appel : pas de ligne → ajout ; 2e appel : ligne créée
        $ligneManager->method('findByControleAndEquipement')
            ->willReturnOnConsecutiveCalls(null, ['id' => 100]);
        $ligneManager->expects(self::once())->method('addEquipement')
            ->with(7, 5)->willReturn(true);
        $controller = $this->makeController(['id' => 1], null, $equipementManager, $controleManager, $ligneManager);

        $response = $controller->redirect($this->makeRequest('/qr/5', 5));

        self::assertSame('/admin/controles/update-ligne/100', $response->getTargetUrl());
    }

    // ==================================================================
    // Endpoints JSON / binaires — chemins d'erreur (avant getBaseUrl)
    // ==================================================================

    public function testSaveQrWithoutIdReturns400Json(): void
    {
        $controller = $this->makeController(user: ['id' => 1]);

        $response = $controller->saveQr(Request::create('/qr/save/'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(['error' => 'Requête invalide'], json_decode((string) $response->getContent(), true));
    }

    public function testSaveQrWithUnknownEquipementReturns404Json(): void
    {
        $controller = $this->makeController(['id' => 1], null, $this->makeEquipementManager(null));

        $response = $controller->saveQr($this->makeRequest('/qr/save/999', 999));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(['error' => 'Ressource non trouvée'], json_decode((string) $response->getContent(), true));
    }

    public function testApiGenerateQrWithoutIdReturns400Json(): void
    {
        $controller = $this->makeController(user: ['id' => 1]);

        $response = $controller->apiGenerateQr(Request::create('/api/qr/generate/'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testApiGenerateQrWithUnknownEquipementReturns404Json(): void
    {
        $controller = $this->makeController(['id' => 1], null, $this->makeEquipementManager(null));

        $response = $controller->apiGenerateQr($this->makeRequest('/api/qr/generate/999', 999));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGenerateQrWithUnknownEquipementReturns404(): void
    {
        $controller = $this->makeController(['id' => 1], null, $this->makeEquipementManager(null));

        $response = $controller->generateQr($this->makeRequest('/qr/generate/999', 999));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testChoicePageWithUnknownEquipementFlashesAndRedirects(): void
    {
        $flashBag = new FlashBag();
        $controller = $this->makeController(['id' => 1], $flashBag, $this->makeEquipementManager(null));

        $response = $controller->choicePage($this->makeRequest('/qr/choix/999', 999));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/equipements', $response->getTargetUrl());
        self::assertSame(['Équipement non trouvé'], $flashBag->peek('danger'));
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeRequest(string $path, ?int $id = null): Request
    {
        $request = Request::create($path);
        if ($id !== null) {
            $request->attributes->set('id', $id);
        }
        return $request;
    }

    private function makeEquipementManager(?array $equipement): EquipementManager
    {
        $manager = $this->getMockBuilder(EquipementManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->method('findId')->willReturn($equipement);
        return $manager;
    }

    /**
     * @param array<string,mixed>|null $user
     */
    private function makeController(
        ?array $user,
        ?FlashBag $flashBag = null,
        ?EquipementManager $equipementManager = null,
        ?ControleManager $controleManager = null,
        ?ControleLigneManager $controleLigneManager = null,
    ): QrRedirectController {
        $session = $this->createMock(Session::class);
        $session->method('isGranted')->willReturn(true);
        $session->method('get')->willReturnCallback(
            static fn(string $key, mixed $default = null) => $key === 'user' ? $user : $default
        );
        $session->method('getFlashBag')->willReturn($flashBag ?? new FlashBag());

        return new TestableQrRedirectController(
            $session,
            $equipementManager,
            $controleManager,
            $controleLigneManager,
        );
    }
}
