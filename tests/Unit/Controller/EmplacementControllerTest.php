<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\EmplacementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableEmplacementController;
use Epiclub\Tests\Unit\Controller\Support\TestableEmplacementControllerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [VAGUE 11 — issue #64]
 *
 * Vérifie que EmplacementController utilise bien la factory
 * emplacementManager() (mockable) au lieu d'instancier
 * EmplacementManager en dur.
 *
 * Sans ce refactor, list/show/edit/delete ouvrent PDO à chaque appel
 * et le test Unit devient impossible.
 */
final class EmplacementControllerTest extends TestCase
{
    private const CSRF_TOKEN = 'test-csrf-token';

    // ==================================================================
    // edit() — validation libelle
    // ==================================================================

    public function testEditGetNominalRendersForm(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->expects(self::never())->method('findId');
        $emplacementManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($emplacementManager);
        $request = Request::create('/admin/emplacements/edit', 'GET');

        $response = $controller->edit($request);

        self::assertInstanceOf(Response::class, $response);
        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditGetWithValidIdLoadsEmplacement(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->expects(self::once())
            ->method('findId')
            ->with(3)
            ->willReturn(['id' => 3, 'libelle' => 'Local A']);

        $controller = $this->makeControllerWith($emplacementManager);
        $request = Request::create('/admin/emplacements/edit/3', 'GET', ['id' => '3']);

        $response = $controller->edit($request);

        self::assertInstanceOf(Response::class, $response);
    }

    public function testEditGetWithUnknownIdRedirects(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->method('findId')->willReturn(null);

        $controller = $this->makeControllerWith($emplacementManager);
        $request = Request::create('/admin/emplacements/edit/999', 'GET', ['id' => '999']);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/emplacements', $response->getTargetUrl());
    }

    public function testEditPostWithEmptyLibelleFailsValidation(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest([
            'libelle'     => '',
            'description' => '',
            'image'       => '',
        ]);

        $response = $controller->edit($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertInstanceOf(Response::class, $response);
    }

    public function testEditPostWithWhitespaceOnlyLibelleFailsValidation(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest([
            'libelle'     => '   ',
            'description' => '',
            'image'       => '',
        ]);

        $response = $controller->edit($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditPostTruncatesLibelleTo64Chars(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);

        $saved = null;
        $emplacementManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $emp) use (&$saved) {
                $saved = $emp;
                return 1;
            });

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest([
            'libelle'     => str_repeat('a', 100),
            'description' => '',
            'image'       => '',
        ]);

        $controller->edit($request);

        self::assertIsArray($saved);
        self::assertSame(64, mb_strlen($saved['libelle']));
    }

    public function testEditPostValidSavesAndRedirects(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);

        $saved = null;
        $emplacementManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $emp) use (&$saved) {
                $saved = $emp;
                return 1;
            });

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest([
            'libelle'     => 'Local A',
            'description' => 'Devant l\'entrée',
            'image'       => '',
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/emplacements', $response->getTargetUrl());
        self::assertIsArray($saved);
        self::assertSame('Local A', $saved['libelle']);
        self::assertSame('Devant l\'entrée', $saved['description']);
        self::assertNull($saved['image']);   // image vide → null
    }

    // ==================================================================
    // edit() — validation URL image (http/https uniquement)
    // ==================================================================

    /**
     * @return array<string, array{string, bool}>
     */
    public static function imageUrlProvider(): array
    {
        return [
            'vide accepté'                 => ['', false],
            'http simple'                  => ['http://example.com/img.png', false],
            'https simple'                 => ['https://example.com/img.png', false],
            'https avec query'             => ['https://cdn.example.com/a.png?v=2', false],
            'https avec port'              => ['https://example.com:8443/img.png', false],
            'sans schéma'                  => ['example.com/img.png', true],
            'javascript: rejeté'           => ['javascript:alert(1)', true],
            'data: rejeté'                 => ['data:image/png;base64,AAAA', true],
            'file: rejeté'                 => ['file:///etc/passwd', true],
            'ftp: rejeté'                  => ['ftp://example.com/img.png', true],
            'mailto: rejeté'               => ['mailto:contact@example.com', true],
            'chaîne quelconque'            => ['pas-une-url', true],
        ];
    }

    #[DataProvider('imageUrlProvider')]
    public function testEditPostImageUrlValidation(string $image, bool $expectError): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);

        if ($expectError) {
            $emplacementManager->expects(self::never())->method('save');
        } else {
            $emplacementManager->expects(self::once())->method('save');
        }

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest([
            'libelle'     => 'Local A',
            'description' => '',
            'image'       => $image,
        ]);

        $response = $controller->edit($request);

        if ($expectError) {
            self::assertNotInstanceOf(RedirectResponse::class, $response);
        } else {
            self::assertInstanceOf(RedirectResponse::class, $response);
        }
    }

    public function testEditPostStoresImageUrlWhenProvided(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);

        $saved = null;
        $emplacementManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $emp) use (&$saved) {
                $saved = $emp;
                return 1;
            });

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest([
            'libelle'     => 'Local A',
            'description' => '',
            'image'       => 'https://example.com/img.png',
        ]);

        $controller->edit($request);

        self::assertIsArray($saved);
        self::assertSame('https://example.com/img.png', $saved['image']);
    }

    public function testEditPostStoresNullImageWhenEmpty(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);

        $saved = null;
        $emplacementManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $emp) use (&$saved) {
                $saved = $emp;
                return 1;
            });

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest([
            'libelle'     => 'Local A',
            'description' => '',
            'image'       => '',
        ]);

        $controller->edit($request);

        self::assertIsArray($saved);
        self::assertNull($saved['image']);
    }

    // ==================================================================
    // delete() — POST-only + refus si équipements
    // ==================================================================

    public function testDeleteGetIsRejectedWithoutCallingManager(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->expects(self::never())->method('findId');
        $emplacementManager->expects(self::never())->method('delete');
        $emplacementManager->expects(self::never())->method('hasEquipements');

        $controller = $this->makeControllerWith($emplacementManager);
        $request = Request::create('/admin/emplacements/delete/3', 'GET', ['id' => '3']);

        $response = $controller->delete($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/emplacements', $response->getTargetUrl());
    }

    public function testDeletePostWithEquipementsIsRefused(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->method('findId')->willReturn(['id' => 3, 'libelle' => 'Local A']);
        $emplacementManager->expects(self::once())
            ->method('hasEquipements')
            ->with(3)
            ->willReturn(true);
        $emplacementManager->expects(self::never())->method('delete');

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest(['id' => '3']);

        $response = $controller->delete($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/emplacements', $response->getTargetUrl());
    }

    public function testDeletePostWithoutEquipementsDeletesAndRedirects(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->method('findId')->willReturn(['id' => 3, 'libelle' => 'Local A']);
        $emplacementManager->method('hasEquipements')->willReturn(false);
        $emplacementManager->expects(self::once())
            ->method('delete')
            ->with(3)
            ->willReturn(true);

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest(['id' => '3']);

        $response = $controller->delete($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/emplacements', $response->getTargetUrl());
    }

    public function testDeletePostWithUnknownIdRedirects(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->method('findId')->willReturn(null);
        $emplacementManager->expects(self::never())->method('delete');

        $controller = $this->makeControllerWith($emplacementManager);
        $request = $this->postRequest(['id' => '999']);

        $response = $controller->delete($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    // ==================================================================
    // list() / show() — smoke tests via factory mockée
    // ==================================================================

    public function testListCallsFindAllOnInjectedManager(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        $controller = $this->makeControllerWith($emplacementManager);
        $request = Request::create('/admin/emplacements', 'GET');

        $response = $controller->list($request);

        self::assertInstanceOf(Response::class, $response);
    }

    public function testShowWithUnknownIdRedirects(): void
    {
        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->method('findId')->willReturn(null);

        $controller = $this->makeControllerWith($emplacementManager);
        $request = Request::create('/admin/emplacements/999', 'GET', ['id' => '999']);

        $response = $controller->show($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/emplacements', $response->getTargetUrl());
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Construit une Request POST avec le token CSRF posé explicitement.
     * (validateCsrf est no-op dans le testable, mais on garde le pattern
     * pour rester aligné sur ClubControllerTest et faciliter un éventuel
     * retour en arrière.)
     *
     * @param array<string, mixed> $params
     */
    private function postRequest(array $params): Request
    {
        $request = Request::create('/admin/emplacements/edit', 'POST');
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

    private function makeControllerWith(?EmplacementManager $emplacementManager = null): TestableEmplacementController
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', [
            'id'       => 1,
            'username' => 'admin',
            'role'     => 'ROLE_ADMIN',
        ]);
        $session->set('csrf_token', self::CSRF_TOKEN);

        $builder = (new TestableEmplacementControllerBuilder($this))
            ->withSession($session);

        if ($emplacementManager !== null) {
            $builder->withEmplacementManager($emplacementManager);
        }

        return $builder->build();
    }
}