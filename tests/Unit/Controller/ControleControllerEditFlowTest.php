<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableControleControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [#111] Flux de ControleController::edit() après découpage en handlers
 * (Priorité 4 de l'audit) : caractérisation comportementale des
 * redirections, du POST remarques générales (chiffrement #52 + CSRF)
 * et du mode readonly.
 */
final class ControleControllerEditFlowTest extends TestCase
{
    public function testEditRedirectsSilentlyWhenControleMissing(): void
    {
        $controleManager = $this->mockManager(ControleManager::class);
        $controleManager->method('findId')->willReturn(null);

        $controller = (new TestableControleControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withControleManager($controleManager)
            ->build();

        $response = $controller->edit(Request::create('/admin/controles/edit/5', 'GET', ['id' => '5']));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/controles', $response->headers->get('Location'));
    }

    public function testEditPostSavesEncryptedRemarqueAndRedirects(): void
    {
        $controle = [
            'id'             => 5,
            'statut'         => 'ouvert',
            'controleur_id'  => 42,
            'date_debut'     => '2026-10-17 08:00:00',
            'hash_remarques' => null,
        ];

        $controleManager = $this->mockManager(ControleManager::class);
        $controleManager->method('findId')->willReturn($controle);
        $controleManager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $saved) {
                // [#52] Jamais stocké en clair : la valeur sauvegardée est
                // le chiffré de 'Ma remarque secrète', pas le texte brut.
                return $saved['id'] === 5
                    && !empty($saved['hash_remarques'])
                    && $saved['hash_remarques'] !== 'Ma remarque secrète';
            }));

        $session = $this->makeSession();
        $controller = (new TestableControleControllerBuilder($this))
            ->withSession($session)
            ->withControleManager($controleManager)
            ->build();

        $request = Request::create('/admin/controles/edit/5', 'POST', [
            'id'                 => '5',
            'csrf_token'         => 'test-csrf-token',
            'remarques_generales' => 'Ma remarque secrète',
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/controles/edit/5', $response->headers->get('Location'));
        self::assertSame(
            ['Remarques générales mises à jour.'],
            $session->getFlashBag()->get('success')
        );
    }

    public function testEditPostIsIgnoredWhenControleCloture(): void
    {
        $controle = [
            'id'             => 5,
            'statut'         => 'cloture',
            'controleur_id'  => 42,
            'date_debut'     => '2026-10-16 08:00:00',
            'hash_remarques' => null,
        ];

        $controleManager = $this->mockManager(ControleManager::class);
        $controleManager->method('findId')->willReturn($controle);
        $controleManager->expects(self::never())->method('save');

        $controller = (new TestableControleControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withControleManager($controleManager)
            ->withControleLigneManager($this->makeLigneManager())
            ->withEquipementManager($this->makeEquipementManager())
            ->build();

        $request = Request::create('/admin/controles/edit/5', 'POST', [
            'id'                 => '5',
            'csrf_token'         => 'test-csrf-token',
            'remarques_generales' => 'Ne doit pas être enregistré',
        ]);

        $response = $controller->edit($request);

        // Pas de redirection : le formulaire est simplement ré-affiché en readonly.
        self::assertInstanceOf(Response::class, $response);
        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testEditRendersFormForOwnerOnGet(): void
    {
        $controle = [
            'id'             => 5,
            'statut'         => 'ouvert',
            'controleur_id'  => 42,
            'date_debut'     => '2026-10-17 08:00:00',
            'hash_remarques' => null,
        ];

        $controleManager = $this->mockManager(ControleManager::class);
        $controleManager->method('findId')->willReturn($controle);

        $controller = (new TestableControleControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withControleManager($controleManager)
            ->withControleLigneManager($this->makeLigneManager())
            ->withEquipementManager($this->makeEquipementManager())
            ->build();

        $response = $controller->edit(Request::create('/admin/controles/edit/5', 'GET', ['id' => '5']));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

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

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', [
            'id'                   => 42,
            'username'             => 'controleur',
            'role'                 => 'ROLE_CONTROLLEUR',
            'controle_en_cours_id' => null,
        ]);
        $session->set('csrf_token', 'test-csrf-token');
        return $session;
    }

    private function makeLigneManager(): ControleLigneManager
    {
        $ligneManager = $this->mockManager(ControleLigneManager::class);
        $ligneManager->method('findByControle')->willReturn([]);
        return $ligneManager;
    }

    private function makeEquipementManager(): \Epiclub\Domain\EquipementManager
    {
        $equipementManager = $this->mockManager(\Epiclub\Domain\EquipementManager::class);
        $equipementManager->method('findAll')->willReturn([]);
        return $equipementManager;
    }
}
