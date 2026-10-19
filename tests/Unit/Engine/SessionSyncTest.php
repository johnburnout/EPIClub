<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableSessionSyncController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [SÉCURITÉ — audit 2026-10-19 S5] L'utilisateur de session est relu en
 * base à chaque requête.
 *
 * Reproduction : avant le correctif, un ADMIN rétrogradé USER (ou un
 * compte supprimé) gardait ses droits tant que sa session vivait.
 */
final class SessionSyncTest extends TestCase
{
    public function testUnUtilisateurRetrogradePerdSesDroitsImmediatement(): void
    {
        $session = $this->sessionAvec(['id' => 7, 'username' => 'adurand', 'role' => 'ROLE_ADMIN']);
        $manager = $this->managerRenvoyant(['id' => 7, 'username' => 'adurand', 'role' => 'ROLE_USER']);

        TestableSessionSyncController::construire($session, $manager);

        self::assertSame('ROLE_USER', $session->get('user')['role']);
        self::assertFalse($session->isGranted('ROLE_ADMIN'));
    }

    public function testUnCompteSupprimeNEstPlusAuthentifie(): void
    {
        $session = $this->sessionAvec(['id' => 7, 'username' => 'adurand', 'role' => 'ROLE_ADMIN']);
        $manager = $this->managerRenvoyant(null);
        $manager->expects(self::never())->method('updateLastActivity');

        TestableSessionSyncController::construire($session, $manager);

        self::assertFalse($session->isAuthenticated());
    }

    public function testLesChampsDeSessionNonPublicsSontConserves(): void
    {
        $session = $this->sessionAvec([
            'id' => 7, 'username' => 'adurand', 'role' => 'ROLE_CONTROLLEUR', 'controle_en_cours_id' => 12,
        ]);
        $manager = $this->managerRenvoyant(['id' => 7, 'username' => 'adurand', 'role' => 'ROLE_CONTROLLEUR']);
        $manager->expects(self::once())->method('updateLastActivity')->with(7);

        TestableSessionSyncController::construire($session, $manager);

        self::assertSame(12, $session->get('user')['controle_en_cours_id']);
        self::assertSame('ROLE_CONTROLLEUR', $session->get('user')['role']);
    }

    public function testAucunAccesBaseSansUtilisateurConnecte(): void
    {
        $manager = $this->getMockBuilder(UtilisateurManager::class)->disableOriginalConstructor()->getMock();
        $manager->expects(self::never())->method('findPublicId');
        $manager->expects(self::never())->method('updateLastActivity');

        TestableSessionSyncController::construire(new Session(new MockArraySessionStorage()), $manager);
    }

    // ------------------------------------------------------------------

    private function sessionAvec(array $user): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', $user);
        return $session;
    }

    private function managerRenvoyant(?array $ligne): UtilisateurManager
    {
        $manager = $this->getMockBuilder(UtilisateurManager::class)->disableOriginalConstructor()->getMock();
        $manager->method('findPublicId')->with(7)->willReturn($ligne);
        return $manager;
    }
}
