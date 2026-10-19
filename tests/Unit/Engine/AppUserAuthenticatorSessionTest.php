<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Engine\Support\TestableAppUserAuthenticator;
use Epiclub\Tests\Unit\Engine\Support\TestableUtilisateurManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit P34] La session ne doit JAMAIS contenir le hash du
 * mot de passe ni les tokens de réinitialisation : un vol de session
 * ou une itération non échappée dans un template ne doit exposer aucun
 * secret.
 */
final class AppUserAuthenticatorSessionTest extends TestCase
{
    private const PLAIN_PASSWORD = 'motdepasse-tres-long';

    public function testSessionDoesNotContainPasswordHash(): void
    {
        $userRow = [
            'id'                  => 7,
            'nom'                 => 'Durand',
            'prenom'              => 'Alice',
            'username'            => 'adurand',
            'email'               => 'a@example.com',
            'password'            => password_hash(self::PLAIN_PASSWORD, PASSWORD_DEFAULT),
            'role'                => 'ROLE_ADMIN',
            'date_creation'       => '2026-01-01 00:00:00',
            'derniere_connexion'  => null,
            'controle_en_cours_id' => null,
            'last_activity'       => null,
            'reset_token'         => 'tokensecret',
            'reset_token_expires' => '2026-01-02 00:00:00',
            'reset_email_sent_at' => '2026-01-01 12:00:00',
        ];

        $manager = new TestableUtilisateurManager($this->makePdoReturning($userRow));

        $session = $this->createMock(Session::class);
        $session->expects(self::once())
            ->method('set')
            ->with(
                'user',
                self::callback(function (array $sessionUser): bool {
                    // Projection non sensible : aucune colonne secrète.
                    return !array_key_exists('password', $sessionUser)
                        && !array_key_exists('reset_token', $sessionUser)
                        && !array_key_exists('reset_token_expires', $sessionUser)
                        && !array_key_exists('reset_email_sent_at', $sessionUser)
                        // ... mais les colonnes métier restent présentes.
                        && $sessionUser['id'] === 7
                        && $sessionUser['role'] === 'ROLE_ADMIN'
                        && array_key_exists('controle_en_cours_id', $sessionUser);
                })
            );

        $authenticator = new TestableAppUserAuthenticator($session, $manager);

        self::assertTrue($authenticator->authenticate('adurand', self::PLAIN_PASSWORD));
    }

    public function testAuthenticateStillRejectsWrongPassword(): void
    {
        $userRow = [
            'id'       => 7,
            'username' => 'adurand',
            'password' => password_hash(self::PLAIN_PASSWORD, PASSWORD_DEFAULT),
        ];

        $manager = new TestableUtilisateurManager($this->makePdoReturning($userRow));

        $session = $this->createMock(Session::class);
        $session->expects(self::never())->method('set');

        $authenticator = new TestableAppUserAuthenticator($session, $manager);

        self::assertFalse($authenticator->authenticate('adurand', 'mauvais-mot-de-passe'));
        self::assertSame('Identifiants incorrects', $authenticator->getError());
    }

    private function makePdoReturning(array $row): \PDO
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($row) {
            $stmt = $this->createMock(\PDOStatement::class);
            // findOneByCriteria → fetch() ; save() → execute() seul.
            $stmt->method('fetch')->willReturn($row);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });
        return $pdo;
    }
}
