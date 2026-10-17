<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * [#107 — Dette 24 vendue] Helpers CSRF de l'installeur.
 *
 * Les étapes de setup/ (step_1, dbms, admin, smtp, club) n'avaient
 * AUCUN jeton CSRF : pendant la fenêtre d'installation (l'installeur
 * est servi sur toutes les URLs par bootstrap), un site tiers pouvait
 * forger les POST — dont la création du compte SUPER_ADMIN avec les
 * identifiants de l'attaquant (détournement d'installation).
 */
final class SetupCsrfHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/setup/includes/helpers.php';
        unset($_SESSION['setup_csrf_token']);
    }

    public function testTokenIsGeneratedAndStableWithinSession(): void
    {
        $first = setup_csrf_token();
        self::assertNotSame('', $first);
        self::assertSame(64, strlen($first)); // 32 octets en hex

        // Deux appels → même token (pas de fuite de jetons).
        self::assertSame($first, setup_csrf_token());
    }

    public function testCsrfFieldContainsHiddenInputWithToken(): void
    {
        $field = setup_csrf_field();

        self::assertStringContainsString('type="hidden"', $field);
        self::assertStringContainsString('name="csrf_token"', $field);
        self::assertStringContainsString('value="' . setup_csrf_token() . '"', $field);
    }

    public function testValidationAcceptsTheGenuineToken(): void
    {
        self::assertTrue(setup_csrf_is_valid(setup_csrf_token()));
    }

    public function testValidationRejectsWrongEmptyAndNullTokens(): void
    {
        self::assertFalse(setup_csrf_is_valid('forged-token'));
        self::assertFalse(setup_csrf_is_valid(''));
        self::assertFalse(setup_csrf_is_valid(null));
    }
}
