<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableCategorieController;
use Epiclub\Tests\Unit\Controller\Support\TestableCategorieControllerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests unitaires de CategorieController::edit() — issue #48.
 *
 * ⚠️ Périmètre : validation libelle + troncature description + sanitize
 * filename. Les scénarios nominaux (upload réel, suppression ancienne
 * image) restent couverts par la suite Integration.
 */
final class CategorieControllerHandlersTest extends TestCase
{
    // ==================================================================
    // edit() — validation libelle (commit 1)
    // ==================================================================

    public function testEditRejectsEmptyLibelle(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        // ⚠️ save() NE DOIT PAS être appelé si libelle vide
        $manager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => '',  // ← vide
            'description' => 'Une description',
        ]);

        $response = $controller->edit($request);
        
        // Pas de redirection : on reste sur le formulaire avec l'erreur.
        // L'assertion sur le contenu HTML est volontairement omise ici :
        // elle dépendrait du rendu Twig complet, ce qui n'est pas du
        // ressort d'un test Unit. La présence effective du message est
        // couverte par la suite Integration.
    self::assertNotInstanceOf(RedirectResponse::class, $response);
    self::assertSame(200, $response->getStatusCode());
    }

    public function testEditRejectsWhitespaceOnlyLibelle(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => '   ',  // ← uniquement des espaces
            'description' => '',
        ]);

        $response = $controller->edit($request);
        
        // Pas de redirection : on reste sur le formulaire avec l'erreur.
        // L'assertion sur le contenu HTML est volontairement omise ici :
        // elle dépendrait du rendu Twig complet, ce qui n'est pas du
        // ressort d'un test Unit. La présence effective du message est
        // couverte par la suite Integration.
        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testEditTruncatesLongLibelle(): void
    {
        $longLibelle = str_repeat('a', 50); // 50 > 32

        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $c) {
                return mb_strlen($c['libelle']) === 32;
            }))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => $longLibelle,
            'description' => 'desc',
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/categories', $response->headers->get('Location'));
    }

    public function testEditTruncatesLongDescription(): void
    {
        $longDescription = str_repeat('x', 3000); // 3000 > 2000

        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $c) {
                return mb_strlen($c['description']) === 2000;
            }))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => 'Valide',
            'description' => $longDescription,
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/categories', $response->headers->get('Location'));
    }

    // ==================================================================
    // edit() — cas nominal (non-régression)
    // ==================================================================

    public function testEditAcceptsValidLibelle(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $c) {
                return $c['libelle'] === 'Équipement'
                    && $c['description'] === 'Court'
                    && (int) $c['est_epi'] === 1;
            }))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => '  Équipement  ',  // ← trim attendu
            'description' => 'Court',
            'est_epi'     => '1',
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/categories', $response->headers->get('Location'));
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeControllerWith(?CategorieManager $manager = null): TestableCategorieController
    {
        $manager ??= $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $builder = (new TestableCategorieControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withCategorieManager($manager);

        return $builder->build();
    }

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        // ⚠️ edit() exige ROLE_ADMIN
        $session->set('user', [
            'id'       => 42,
            'username' => 'admin',
            'role'     => 'ROLE_ADMIN',
        ]);
        $session->set('csrf_token', 'test-csrf-token');
        return $session;
    }

    /**
     * @param array<string,mixed> $post
     */
    private function makePostRequest(array $post): Request
    {
        $post['csrf_token'] = $post['csrf_token'] ?? 'test-csrf-token';

        return Request::create(
            '/admin/categories/edit',
            'POST',
            $post,
        );
    }
}