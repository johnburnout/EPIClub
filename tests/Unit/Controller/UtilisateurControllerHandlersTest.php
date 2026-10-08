<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableUtilisateurController;
use Epiclub\Tests\Unit\Controller\Support\TestableUtilisateurControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests Unit des handlers d'UtilisateurController (issue #53).
 *
 * Périmètre : validation MDP (12 chars) + troncature des champs
 * utilisateur (nom, prenom, username).
 */
final class UtilisateurControllerHandlersTest extends TestCase
{
    // ==================================================================
    // edit() — création admin
    // ==================================================================

    public function testEditRejectsPasswordShorterThan12(): void
    {
        $manager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        // ⚠️ save NE DOIT PAS être appelé (MDP trop court)
        $manager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'nom'      => 'Doe',
            'prenom'   => 'John',
            'username' => 'jdoe',
            'email'    => 'jdoe@example.com',
            'role'     => 'ROLE_USER',
            'password' => 'short',          // < 12
        ]);

        $response = $controller->edit($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testEditAcceptsValidPassword(): void
    {
        $manager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::once())->method('save');

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'nom'      => 'Doe',
            'prenom'   => 'John',
            'username' => 'jdoe',
            'email'    => 'jdoe@example.com',
            'role'     => 'ROLE_USER',
            'password' => 'strongpassword',  // 14 chars
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditTruncatesLongFields(): void
    {
        $manager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        // ⚠️ save DOIT être appelé avec des champs tronqués à 32 chars
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $u) {
                return mb_strlen($u['nom']) === 32
                    && mb_strlen($u['prenom']) === 32
                    && mb_strlen($u['username']) === 32
                    && $u['email'] === 'jdoe@example.com'; // email non tronqué
            }));

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'nom'      => str_repeat('a', 50),   // > 32
            'prenom'   => str_repeat('b', 50),
            'username' => str_repeat('c', 50),
            'email'    => 'jdoe@example.com',
            'role'     => 'ROLE_USER',
            'password' => 'strongpassword',
        ]);

        $controller->edit($request);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeControllerWith(UtilisateurManager $manager): TestableUtilisateurController
    {
        return (new TestableUtilisateurControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withUtilisateurManager($manager)
            ->build();
    }

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        // ⚠️ edit() exige ROLE_ADMIN, et session user avec role pour UserRole::listAssignableBy()
        $session->set('user', [
            'id'       => 42,
            'username' => 'admin',
            'role'     => 'ROLE_SUPER_ADMIN',  // pour avoir tous les rôles assignables
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
            '/admin/utilisateurs/nouveau',
            'POST',
            $post,
        );
    }
}