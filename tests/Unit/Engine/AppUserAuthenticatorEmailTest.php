<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Engine\Support\TestableAppUserAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-25] Connexion au choix par identifiant ou par email.
 */
final class AppUserAuthenticatorEmailTest extends TestCase
{
    private const MDP = 'motdepasse-tres-long';
    private static ?string $hash = null;

    /** @var list<array> Critères de recherche passés au manager */
    private array $recherches = [];

    private function authentifier(string $saisie, string $mdp = self::MDP): bool
    {
        self::$hash ??= password_hash(self::MDP, PASSWORD_DEFAULT);
        $comptes = [
            ['id' => 1, 'username' => 'adurand', 'email' => 'anne.durand@club.test', 'role' => 'ROLE_USER', 'password' => self::$hash],
            ['id' => 2, 'username' => 'paul@club', 'email' => 'paul@exemple.test', 'role' => 'ROLE_USER', 'password' => self::$hash],
        ];
        $manager = $this->createMock(UtilisateurManager::class);
        $manager->method('findOneByCriteria')->willReturnCallback(function (array $criteres) use ($comptes) {
            $this->recherches[] = $criteres;
            [$champ, $valeur] = [array_key_first($criteres), reset($criteres)];
            foreach ($comptes as $c) {
                if (mb_strtolower($c[$champ]) === mb_strtolower($valeur)) {
                    return $c;
                }
            }
            return null;
        });

        $session = new Session(new MockArraySessionStorage());
        return (new TestableAppUserAuthenticator($session, $manager))->authenticate($saisie, $mdp, '203.0.113.7');
    }

    public function testConnexionParIdentifiant(): void
    {
        self::assertTrue($this->authentifier('adurand'));
        self::assertSame([['username' => 'adurand']], $this->recherches);
    }

    public function testConnexionParEmail(): void
    {
        self::assertTrue($this->authentifier(' Anne.Durand@club.test '));
        self::assertSame(['email' => 'Anne.Durand@club.test'], $this->recherches[1]);
    }

    public function testIdentifiantContenantUneArobase(): void
    {
        self::assertTrue($this->authentifier('paul@club'));
        self::assertCount(1, $this->recherches, 'trouvé par identifiant, sans chercher l\'email');
    }

    public function testMauvaisMotDePasseOuEmailInconnu(): void
    {
        self::assertFalse($this->authentifier('anne.durand@club.test', 'faux-mot-de-passe'));
        self::assertFalse($this->authentifier('inconnu@club.test'));
        self::assertFalse($this->authentifier('inconnu'));
    }
}
