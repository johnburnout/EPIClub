<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MotDePasse;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Engine\Support\TestableAppUserAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [SÉCURITÉ — audit 2026-11-08 R5] bcrypt coût 12 et rehachage
 * transparent des anciens hachages à la connexion.
 */
final class MotDePasseTest extends TestCase
{
    private const MDP = 'motdepasse-tres-long';

    public function testHachageBcryptCout12(): void
    {
        $hash = MotDePasse::hacher(self::MDP);
        self::assertStringStartsWith('$2y$12$', $hash);
        self::assertTrue(password_verify(self::MDP, $hash));
        self::assertFalse(MotDePasse::doitEtreRehache($hash));
    }

    public function testAncienHachageCout10ARehacher(): void
    {
        $ancien = password_hash(self::MDP, PASSWORD_BCRYPT, ['cost' => 10]);
        self::assertTrue(MotDePasse::doitEtreRehache($ancien));
    }

    public function testConnexionRehacheUnAncienHachage(): void
    {
        $nouveau = null;
        $manager = $this->manager(password_hash(self::MDP, PASSWORD_BCRYPT, ['cost' => 4]));
        $manager->expects(self::once())->method('changePassword')
            ->willReturnCallback(function (int $id, string $hash) use (&$nouveau) {
                self::assertSame(7, $id);
                $nouveau = $hash;
                return true;
            });

        self::assertTrue($this->authentifier($manager, self::MDP));
        self::assertStringStartsWith('$2y$12$', (string) $nouveau);
        self::assertTrue(password_verify(self::MDP, (string) $nouveau));
    }

    public function testPasDeRehachageSiDejaAuCoutCourant(): void
    {
        $manager = $this->manager(MotDePasse::hacher(self::MDP));
        $manager->expects(self::never())->method('changePassword');
        self::assertTrue($this->authentifier($manager, self::MDP));
    }

    public function testPasDeRehachageSurEchec(): void
    {
        $manager = $this->manager(password_hash(self::MDP, PASSWORD_BCRYPT, ['cost' => 4]));
        $manager->expects(self::never())->method('changePassword');
        self::assertFalse($this->authentifier($manager, 'mauvais-mot-de-passe'));
    }

    public function testEchecDuRehachageNEmpechePasLaConnexion(): void
    {
        $manager = $this->manager(password_hash(self::MDP, PASSWORD_BCRYPT, ['cost' => 4]));
        $manager->method('changePassword')->willThrowException(new \PDOException('base indisponible'));
        $log = ini_set('error_log', '/dev/null');
        try {
            self::assertTrue($this->authentifier($manager, self::MDP));
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    private function manager(string $hash): UtilisateurManager&\PHPUnit\Framework\MockObject\MockObject
    {
        $manager = $this->createMock(UtilisateurManager::class);
        $manager->method('findOneByCriteria')->willReturn(
            ['id' => 7, 'username' => 'adurand', 'email' => 'a@club.test', 'role' => 'ROLE_USER', 'password' => $hash]
        );
        return $manager;
    }

    private function authentifier(UtilisateurManager $manager, string $mdp): bool
    {
        $auth = new TestableAppUserAuthenticator(new Session(new MockArraySessionStorage()), $manager);
        return $auth->authenticate('adurand', $mdp, '203.0.113.7');
    }
}
