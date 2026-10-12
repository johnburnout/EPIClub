<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableFournisseurController;
use Epiclub\Tests\Unit\Controller\Support\TestableFournisseurControllerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [VAGUE 11 — issue #65]
 *
 * Vérifie que FournisseurController utilise bien la factory
 * fournisseurManager() (mockable) au lieu d'instancier
 * FournisseurManager en dur.
 *
 * Dernier ticket de la Vague 11.
 */
final class FournisseurControllerTest extends TestCase
{
    private const CSRF_TOKEN = 'test-csrf-token';

    // ==================================================================
    // edit() — validation nom
    // ==================================================================

    public function testEditGetNominalRendersForm(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->expects(self::never())->method('findId');
        $fournisseurManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = Request::create('/admin/fournisseurs/edit', 'GET');

        $response = $controller->edit($request);

        self::assertInstanceOf(Response::class, $response);
        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditGetWithValidIdLoadsFournisseur(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->expects(self::once())
            ->method('findId')
            ->with(7)
            ->willReturn(['id' => 7, 'nom' => 'Petzl']);

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = Request::create('/admin/fournisseurs/edit/7', 'GET', ['id' => '7']);

        $response = $controller->edit($request);

        self::assertInstanceOf(Response::class, $response);
    }

    public function testEditGetWithUnknownIdRedirects(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->method('findId')->willReturn(null);

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = Request::create('/admin/fournisseurs/edit/999', 'GET', ['id' => '999']);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/fournisseurs', $response->getTargetUrl());
    }

    public function testEditPostWithEmptyNomFailsValidation(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest([
            'nom'   => '',
            'email' => '',
            'phone' => '',
        ]);

        $response = $controller->edit($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertInstanceOf(Response::class, $response);
    }

    public function testEditPostWithWhitespaceOnlyNomFailsValidation(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest([
            'nom'   => '   ',
            'email' => '',
            'phone' => '',
        ]);

        $response = $controller->edit($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditPostTruncatesNomTo64Chars(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);

        $saved = null;
        $fournisseurManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $fourn) use (&$saved) {
                $saved = $fourn;
                return 1;
            });

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest([
            'nom'   => str_repeat('a', 100),
            'email' => '',
            'phone' => '',
        ]);

        $controller->edit($request);

        self::assertIsArray($saved);
        self::assertSame(64, mb_strlen($saved['nom']));
    }

    public function testEditPostValidSavesAndRedirects(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);

        $saved = null;
        $fournisseurManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $fourn) use (&$saved) {
                $saved = $fourn;
                return 1;
            });

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest([
            'nom'   => 'Petzl',
            'email' => 'contact@petzl.com',
            'phone' => '0102030405',
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/fournisseurs', $response->getTargetUrl());
        self::assertIsArray($saved);
        self::assertSame('Petzl', $saved['nom']);
        self::assertSame('contact@petzl.com', $saved['email']);
        self::assertSame('0102030405', $saved['phone']);
    }

    // ==================================================================
    // edit() — validation email (dataProvider, souple : optionnel)
    // ==================================================================

    /**
     * @return array<string, array{string, bool}>
     */
    public static function emailProvider(): array
    {
        return [
            'vide accepté'          => ['', false],
            'valide simple'         => ['contact@petzl.com', false],
            'valide avec plus'      => ['info+ventes@petzl.com', false],
            'invalide sans @'       => ['pas-un-email', true],
            'invalide sans domaine' => ['user@', true],
            'invalide sans user'    => ['@petzl.com', true],
        ];
    }

    #[DataProvider('emailProvider')]
    public function testEditPostEmailValidation(string $email, bool $expectError): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);

        if ($expectError) {
            $fournisseurManager->expects(self::never())->method('save');
        } else {
            $fournisseurManager->expects(self::once())->method('save');
        }

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest([
            'nom'   => 'Petzl',
            'email' => $email,
            'phone' => '',
        ]);

        $response = $controller->edit($request);

        if ($expectError) {
            self::assertNotInstanceOf(RedirectResponse::class, $response);
        } else {
            self::assertInstanceOf(RedirectResponse::class, $response);
        }
    }

    public function testEditPostStoresNullEmailAndPhoneWhenEmpty(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);

        $saved = null;
        $fournisseurManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $fourn) use (&$saved) {
                $saved = $fourn;
                return 1;
            });

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest([
            'nom'   => 'Petzl',
            'email' => '',
            'phone' => '',
        ]);

        $controller->edit($request);

        self::assertIsArray($saved);
        self::assertNull($saved['email']);
        self::assertNull($saved['phone']);
    }

    // ==================================================================
    // delete() — POST-only + refus si acquisitions
    // ==================================================================

    public function testDeleteGetIsRejectedWithoutCallingManager(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->expects(self::never())->method('findId');
        $fournisseurManager->expects(self::never())->method('delete');
        $fournisseurManager->expects(self::never())->method('hasAcquisitions');

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = Request::create('/admin/fournisseurs/delete/7', 'GET', ['id' => '7']);

        $response = $controller->delete($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/fournisseurs', $response->getTargetUrl());
    }

    public function testDeletePostWithAcquisitionsIsRefused(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->method('findId')->willReturn(['id' => 7, 'nom' => 'Petzl']);
        $fournisseurManager->expects(self::once())
            ->method('hasAcquisitions')
            ->with(7)
            ->willReturn(true);
        $fournisseurManager->expects(self::never())->method('delete');

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest(['id' => '7']);

        $response = $controller->delete($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/fournisseurs', $response->getTargetUrl());
    }

    public function testDeletePostWithoutAcquisitionsDeletesAndRedirects(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->method('findId')->willReturn(['id' => 7, 'nom' => 'Petzl']);
        $fournisseurManager->method('hasAcquisitions')->willReturn(false);
        $fournisseurManager->expects(self::once())
            ->method('delete')
            ->with(7)
            ->willReturn(true);

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest(['id' => '7']);

        $response = $controller->delete($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/fournisseurs', $response->getTargetUrl());
    }

    public function testDeletePostWithUnknownIdRedirects(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->method('findId')->willReturn(null);
        $fournisseurManager->expects(self::never())->method('delete');

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = $this->postRequest(['id' => '999']);

        $response = $controller->delete($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    // ==================================================================
    // list() / show() — smoke tests via factory mockée
    // ==================================================================

    public function testListCallsFindAllOnInjectedManager(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = Request::create('/admin/fournisseurs', 'GET');

        $response = $controller->list($request);

        self::assertInstanceOf(Response::class, $response);
    }

    public function testShowWithUnknownIdRedirects(): void
    {
        $fournisseurManager = $this->mockManager(FournisseurManager::class);
        $fournisseurManager->method('findId')->willReturn(null);

        $controller = $this->makeControllerWith($fournisseurManager);
        $request = Request::create('/admin/fournisseurs/999', 'GET', ['id' => '999']);

        $response = $controller->show($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/fournisseurs', $response->getTargetUrl());
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Construit une Request POST avec le token CSRF posé explicitement.
     * (validateCsrf est no-op dans le testable, mais on garde le pattern
     * par cohérence avec ClubControllerTest / EmplacementControllerTest.)
     *
     * @param array<string, mixed> $params
     */
    private function postRequest(array $params): Request
    {
        $request = Request::create('/admin/fournisseurs/edit', 'POST');
        $request->request->replace($params);
        $request->request->set('csrf_token', self::CSRF_TOKEN);
        return $request;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function mockManager(string $class): object
    {
        return (new \PHPUnit\Framework\MockObject\MockBuilder($this, $class))
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function makeControllerWith(?FournisseurManager $fournisseurManager = null): TestableFournisseurController
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', [
            'id'       => 1,
            'username' => 'admin',
            'role'     => 'ROLE_ADMIN',
        ]);
        $session->set('csrf_token', self::CSRF_TOKEN);

        $builder = (new TestableFournisseurControllerBuilder($this))
            ->withSession($session);

        if ($fournisseurManager !== null) {
            $builder->withFournisseurManager($fournisseurManager);
        }

        return $builder->build();
    }
}