<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableUtilisateurManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit C3] save() en mise à jour ne doit JAMAIS réécrire
 * les colonnes sensibles (password, role, reset_token*) : mass
 * assignment. Seules les méthodes dédiées changePassword() /
 * updateRole() / setResetToken() / clearResetToken() y touchent.
 *
 * La création, elle, doit toujours accepter password/role (step_admin,
 * création de compte par un admin).
 */
final class UtilisateurManagerSensitiveFieldsTest extends TestCase
{
    public function testResetPasswordDoesNotOverwriteRole(): void
    {
        // Scénario de l'audit : un save() complet (ex. après reset de
        // mot de passe) transporte un tableau complet — y compris des
        // colonnes sensibles potentiellement périmétrées ou forgées.
        [$pdo, $captured] = $this->makeCapturingPdo();

        $manager = new TestableUtilisateurManager($pdo);
        $manager->save([
            'id'                    => 7,
            'nom'                   => 'Durand',
            'password'              => '$2y$hashforge',
            'role'                  => 'ROLE_USER',
            'reset_token'           => 'tokenforge',
            'reset_token_expires'   => '2030-01-01 00:00:00',
            'reset_email_sent_at'   => '2030-01-01 00:00:00',
        ]);

        self::assertNotNull($captured->sql);
        self::assertStringContainsString('UPDATE utilisateur', $captured->sql);
        self::assertStringContainsString('nom', $captured->sql);
        // Aucune colonne sensible dans le SET.
        self::assertStringNotContainsString('password', $captured->sql);
        self::assertStringNotContainsString('role', $captured->sql);
        self::assertStringNotContainsString('reset_token', $captured->sql);
        self::assertStringNotContainsString('reset_email_sent_at', $captured->sql);
        // Ni dans les paramètres liés.
        self::assertArrayNotHasKey('password', $captured->params ?? []);
        self::assertArrayNotHasKey('role', $captured->params ?? []);
    }

    public function testChangePasswordUpdatesOnlyPassword(): void
    {
        [$pdo, $captured] = $this->makeCapturingPdo();

        $manager = new TestableUtilisateurManager($pdo);
        $manager->changePassword(7, '$2y$nouveauhash');

        self::assertSame(
            'UPDATE utilisateur SET password = :password WHERE id = :id',
            $captured->sql
        );
        self::assertSame('$2y$nouveauhash', $captured->params['password']);
        self::assertSame(7, $captured->params['id']);
    }

    public function testUpdateRoleUpdatesOnlyRole(): void
    {
        [$pdo, $captured] = $this->makeCapturingPdo();

        $manager = new TestableUtilisateurManager($pdo);
        $manager->updateRole(7, 'ROLE_CONTROLLEUR');

        self::assertSame(
            'UPDATE utilisateur SET role = :role WHERE id = :id',
            $captured->sql
        );
        self::assertSame('ROLE_CONTROLLEUR', $captured->params['role']);
    }

    public function testCreateStillAcceptsPasswordAndRole(): void
    {
        // La création (pas de clé 'id') doit conserver password/role :
        // step_admin et la création de compte admin en ont besoin.
        [$pdo, $captured] = $this->makeCapturingPdo();

        $manager = new TestableUtilisateurManager($pdo);
        $manager->save([
            'nom'      => 'Durand',
            'prenom'   => 'Alice',
            'username' => 'adurand',
            'email'    => 'a@example.com',
            'password' => '$2y$hash',
            'role'     => 'ROLE_SUPER_ADMIN',
        ]);

        self::assertStringContainsString('INSERT', $captured->sql);
        self::assertStringContainsString('password', $captured->sql);
        self::assertStringContainsString('role', $captured->sql);
    }

    /**
     * @return array{0: \PDO, 1: \stdClass}
     */
    private function makeCapturingPdo(): array
    {
        $captured = new \stdClass();
        $captured->sql = null;
        $captured->params = null;

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($captured) {
            $captured->sql = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (?array $params) use ($captured) {
                $captured->params = $params;
                return true;
            });
            return $stmt;
        });
        $pdo->method('lastInsertId')->willReturn('7');

        return [$pdo, $captured];
    }
}
