<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\UtilisateurManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests Unit pour UtilisateurManager (issue #53).
 *
 * Périmètre : constante MIN_PASSWORD_LENGTH + méthode statique
 * validatePassword(). Les méthodes d'accès BDD sont couvertes par
 * la suite Integration.
 */
final class UtilisateurManagerTest extends TestCase
{
    public function testMinPasswordLengthIsTwelve(): void
    {
        self::assertSame(12, UtilisateurManager::MIN_PASSWORD_LENGTH);
    }

    /**
     * @return array<string, array{0:string, 1:bool}>
     */
    public static function passwordProvider(): array
    {
        return [
            'vide'                => ['', false],
            '1 caractère'         => ['a', false],
            '11 caractères'       => ['12345678901', false],
            '12 caractères'       => ['123456789012', true],
            '13 caractères'       => ['1234567890123', true],
            'long'                => [str_repeat('a', 100), true],
            'accents (12 chars)'  => ['éééééééééééé', true],
        ];
    }

    #[DataProvider('passwordProvider')]
    public function testValidatePassword(
        string $password,
        bool $shouldBeValid,
    ): void {
        $error = UtilisateurManager::validatePassword($password);

        if ($shouldBeValid) {
            self::assertNull($error, "Le mot de passe '$password' devrait être valide");
        } else {
            self::assertNotNull($error, "Le mot de passe '$password' devrait être rejeté");
            self::assertStringContainsString('12', $error);
        }
    }

    public function testValidatePasswordErrorMessageMentionsMinLength(): void
    {
        $error = UtilisateurManager::validatePassword('short');

        self::assertNotNull($error);
        self::assertStringContainsString('12 caractères', $error);
    }
}