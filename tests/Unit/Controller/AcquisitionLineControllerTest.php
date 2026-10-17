<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Process\AcquisitionProcess;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionLineController;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * [#99 — Dette 19 soldée]
 *
 * AcquisitionLineController était le SEUL contrôleur non migré Vague 11 :
 * `new AcquisitionLigneManager()`, `new CategorieManager()`,
 * `new AcquisitionManager()` et `new AcquisitionProcess()` en dur dans
 * les méthodes publiques — non testable en Unit. Les 4 factories
 * protected rendent les mocks possibles (pattern des 16 autres
 * contrôleurs).
 */
final class AcquisitionLineControllerTest extends TestCase
{
    private const CSRF_TOKEN = 'test-csrf-token';

    private const LIGNE = [
        'id'                  => 5,
        'acquisition_id'      => 7,
        'reference'           => 'REF-1',
        'designation'         => 'Casque Black Diamond',
        'categorie_id'        => 2,
        'nombre'              => 3,
        'regrouper_en_lot'    => 0,
        'equipements_generes' => 0,
    ];

    // ==================================================================
    // modifyLine
    // ==================================================================

    public function testModifyLinePostSuccessSavesAndRedirects(): void
    {
        $ligneManager = $this->mockManager(AcquisitionLigneManager::class);
        $ligneManager->method('findId')->with(5)->willReturn(self::LIGNE);
        // Validator : pas de doublon de référence.
        $ligneManager->method('findByReference')->willReturn(null);
        $ligneManager->expects(self::once())
            ->method('save')
            ->with(self::callback(fn (array $ligne) => $ligne['categorie_id'] === 9));

        $acquisitionManager = $this->mockManager(AcquisitionManager::class);
        $acquisitionManager->method('findId')->with(7)->willReturn(['id' => 7, 'est_validee' => 0]);

        $categorieManager = $this->mockManager(CategorieManager::class);

        $process = $this->mockManager(AcquisitionProcess::class);
        $process->method('categorie_process')->willReturn(9);

        $controller = $this->makeController($ligneManager, $categorieManager, $acquisitionManager, $process);

        $response = $controller->modifyLine($this->postRequest([
            'id'                => 5,
            'reference'         => 'REF-1',
            'designation'       => 'Casque Black Diamond',
            'categorie_libelle' => 'Casques',
            'nombre'            => 3,
        ]));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/acquisitions/acquisition_modification-7', $response->getTargetUrl());
    }

    public function testModifyLineRefusedWhenAcquisitionValidated(): void
    {
        $ligneManager = $this->mockManager(AcquisitionLigneManager::class);
        $ligneManager->method('findId')->with(5)->willReturn(self::LIGNE);
        $ligneManager->expects(self::never())->method('save');

        $acquisitionManager = $this->mockManager(AcquisitionManager::class);
        $acquisitionManager->method('findId')->with(7)->willReturn(['id' => 7, 'est_validee' => 1]);

        $controller = $this->makeController($ligneManager, null, $acquisitionManager);

        $response = $controller->modifyLine($this->postRequest(['id' => 5]));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/acquisitions/acquisition-7', $response->getTargetUrl());
    }

    public function testModifyLineRendersFormWithErrorsOnInvalidData(): void
    {
        $ligneManager = $this->mockManager(AcquisitionLigneManager::class);
        $ligneManager->method('findId')->with(5)->willReturn(self::LIGNE);
        $ligneManager->expects(self::never())->method('save');

        $acquisitionManager = $this->mockManager(AcquisitionManager::class);
        $acquisitionManager->method('findId')->with(7)->willReturn(['id' => 7, 'est_validee' => 0]);

        $controller = $this->makeController($ligneManager, null, $acquisitionManager);

        $response = $controller->modifyLine($this->postRequest([
            'id'                => 5,
            'reference'         => '',
            'designation'       => 'Casque',
            'categorie_libelle' => 'Casques',
            'nombre'            => 3,
        ]));

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // deleteLine
    // ==================================================================

    public function testDeleteLineSuccessDeletesAndRedirects(): void
    {
        $ligneManager = $this->mockManager(AcquisitionLigneManager::class);
        $ligneManager->method('findId')->with(5)->willReturn(self::LIGNE);
        $ligneManager->expects(self::once())->method('delete')->with(5);

        $acquisitionManager = $this->mockManager(AcquisitionManager::class);
        $acquisitionManager->method('findId')->with(7)->willReturn(['id' => 7, 'est_validee' => 0]);

        $controller = $this->makeController($ligneManager, null, $acquisitionManager);

        $response = $controller->deleteLine($this->postRequest(['id' => 5]));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/acquisitions/acquisition_modification-7', $response->getTargetUrl());
    }

    public function testDeleteLineRefusedWhenEquipementsGeneres(): void
    {
        $ligne = self::LIGNE;
        $ligne['equipements_generes'] = 1;

        $ligneManager = $this->mockManager(AcquisitionLigneManager::class);
        $ligneManager->method('findId')->with(5)->willReturn($ligne);
        $ligneManager->expects(self::never())->method('delete');

        $acquisitionManager = $this->mockManager(AcquisitionManager::class);
        $acquisitionManager->method('findId')->with(7)->willReturn(['id' => 7, 'est_validee' => 0]);

        $controller = $this->makeController($ligneManager, null, $acquisitionManager);

        $response = $controller->deleteLine($this->postRequest(['id' => 5]));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/acquisitions/acquisition_modification-7', $response->getTargetUrl());
    }

    public function testModifyLineDeniesAccessWhenUserIsNotAdmin(): void
    {
        $controller = new TestableAcquisitionLineController($this->mockSession(false));

        $this->expectException(AccessDeniedException::class);
        $controller->modifyLine($this->postRequest(['id' => 5]));
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeController(
        AcquisitionLigneManager $ligneManager,
        ?CategorieManager $categorieManager,
        AcquisitionManager $acquisitionManager,
        ?AcquisitionProcess $process = null,
    ): TestableAcquisitionLineController {
        return new TestableAcquisitionLineController(
            $this->mockSession(true),
            $ligneManager,
            $categorieManager,
            $acquisitionManager,
            $process,
        );
    }

    private function postRequest(array $params): Request
    {
        $request = Request::create('/admin/acquisitions', 'POST');
        $request->request->replace($params);
        $request->request->set('csrf_token', self::CSRF_TOKEN);
        return $request;
    }

    private function mockSession(bool $granted = true): Session
    {
        $session = $this->getMockBuilder(Session::class)->getMock();
        $session->method('get')->willReturn(null);
        $session->method('isAuthenticated')->willReturn($granted);
        $session->method('isGranted')->willReturn($granted);
        $session->method('getFlashBag')->willReturn(new FlashBag());
        return $session;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function mockManager(string $class): object
    {
        return (new MockBuilder($this, $class))
            ->disableOriginalConstructor()
            ->getMock();
    }
}
