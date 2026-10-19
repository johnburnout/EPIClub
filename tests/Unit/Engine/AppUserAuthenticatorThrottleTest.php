<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\LoginThrottleManager;
use Epiclub\Engine\AppUserAuthenticator;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Domain\Support\InMemoryLoginThrottleManager;
use Epiclub\Tests\Unit\Engine\Support\TestableAppUserAuthenticator;
use Epiclub\Tests\Unit\Engine\Support\TestableUtilisateurManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit 2026-10-19 S2] Protection contre la force brute.
 *
 * Reproduction : avant le correctif, un attaquant pouvait essayer un
 * nombre illimité de mots de passe ; le bon, trouvé au 1 000e essai,
 * ouvrait la session.
 */
final class AppUserAuthenticatorThrottleTest extends TestCase
{
    private const BON = 'motdepasse-tres-long';
    private const IP = '203.0.113.7';

    private static ?string $hash = null;

    public function testLeBonMotDePasseEstRefuseApres5Echecs(): void
    {
        $limiteur = new InMemoryLoginThrottleManager();
        $auth = $this->authentificateur($this->utilisateur(), $limiteur);

        for ($i = 1; $i <= 5; $i++) {
            self::assertFalse($auth->authenticate('adurand', "essai-$i", self::IP));
        }

        // 6e tentative avec le BON mot de passe : refusée tant que le blocage dure.
        self::assertFalse($auth->authenticate('adurand', self::BON, self::IP));
        self::assertStringContainsString('Trop de tentatives', $auth->getError());
        self::assertStringContainsString('1 minute', $auth->getError());
    }

    public function testLaConnexionRedevientPossibleApresLeBlocageEtRemetLeCompteurAZero(): void
    {
        $limiteur = new InMemoryLoginThrottleManager();
        $auth = $this->authentificateur($this->utilisateur(), $limiteur);

        for ($i = 1; $i <= 5; $i++) {
            $auth->authenticate('adurand', 'faux', self::IP);
        }
        $limiteur->horloge += 61;

        self::assertTrue($auth->authenticate('adurand', self::BON, self::IP));
        self::assertSame(0, $limiteur->secondesRestantes('login-user:adurand'));
        // Compteur remis à zéro : un échec isolé ne rebloque pas.
        $auth->authenticate('adurand', 'faux', self::IP);
        self::assertSame(0, $limiteur->secondesRestantes('login-user:adurand'));
    }

    public function testLIdentifiantEstInsensibleALaCasseEtAuxEspaces(): void
    {
        $limiteur = new InMemoryLoginThrottleManager();
        $auth = $this->authentificateur($this->utilisateur(), $limiteur);

        foreach (['adurand', 'ADurand', ' adurand ', 'ADURAND', 'Adurand'] as $variante) {
            $auth->authenticate($variante, 'faux', self::IP);
        }

        self::assertGreaterThan(0, $limiteur->secondesRestantes('login-user:adurand'));
    }

    public function testUnCompteInexistantEstTraiteCommeUnCompteExistant(): void
    {
        // Pas d'énumération : même compteur, même message, même blocage.
        $limiteur = new InMemoryLoginThrottleManager();
        $auth = $this->authentificateur(false, $limiteur);

        for ($i = 1; $i <= 5; $i++) {
            self::assertFalse($auth->authenticate('inconnu', 'faux', self::IP));
            self::assertSame('Identifiants incorrects', $auth->getError());
        }
        $auth->authenticate('inconnu', 'faux', self::IP);
        self::assertStringContainsString('Trop de tentatives', $auth->getError());
    }

    public function testUneIpQuiEssaieDeNombreuxIdentifiantsEstBloquee(): void
    {
        $limiteur = new InMemoryLoginThrottleManager();
        $auth = $this->authentificateur(false, $limiteur);

        for ($i = 1; $i <= AppUserAuthenticator::SEUIL_IP; $i++) {
            $auth->authenticate("compte$i", 'faux', self::IP);
        }

        self::assertFalse($auth->authenticate('compte-neuf', 'faux', self::IP));
        self::assertStringContainsString('Trop de tentatives', $auth->getError());
        // Une autre IP n'est pas concernée.
        $auth->authenticate('compte-neuf', 'faux', '198.51.100.1');
        self::assertSame('Identifiants incorrects', $auth->getError());
    }

    public function testUnLimiteurIndisponibleNEmpechePasDeSeConnecter(): void
    {
        $enPanne = $this->createMock(LoginThrottleManager::class);
        $enPanne->method('secondesRestantes')->willThrowException(new \PDOException('table absente'));
        $enPanne->method('enregistrerEchec')->willThrowException(new \PDOException('table absente'));
        $enPanne->method('reinitialiser')->willThrowException(new \PDOException('table absente'));

        $auth = $this->authentificateur($this->utilisateur(), $enPanne);

        self::assertTrue($auth->authenticate('adurand', self::BON, self::IP));
    }

    // ------------------------------------------------------------------

    private function authentificateur(array|false $ligne, LoginThrottleManager $limiteur): TestableAppUserAuthenticator
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use ($ligne) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn($ligne);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });

        return new TestableAppUserAuthenticator(
            $this->createMock(Session::class),
            new TestableUtilisateurManager($pdo),
            $limiteur,
        );
    }

    private function utilisateur(): array
    {
        self::$hash ??= password_hash(self::BON, PASSWORD_DEFAULT);
        return ['id' => 7, 'username' => 'adurand', 'role' => 'ROLE_USER', 'password' => self::$hash];
    }
}
