<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [migration 020] TOTP (RFC 6238) — vecteurs de test officiels (SHA-1,
 * clé ASCII « 12345678901234567890 », 6 derniers chiffres des codes à 8).
 */
final class TotpTest extends TestCase
{
    private const SECRET_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testBase32DuSecretRfc(): void
    {
        self::assertSame(self::SECRET_RFC, Totp::base32Encoder('12345678901234567890'));
        self::assertSame('12345678901234567890', Totp::base32Decoder(self::SECRET_RFC));
    }

    /** @return array<string, array{int, string}> */
    public static function vecteursRfc(): array
    {
        return [
            '59'         => [59, '287082'],
            '1111111109' => [1111111109, '081804'],
            '1111111111' => [1111111111, '050471'],
            '1234567890' => [1234567890, '005924'],
            '2000000000' => [2000000000, '279037'],
            '20000000000' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('vecteursRfc')]
    public function testVecteursRfc6238(int $horodatage, string $attendu): void
    {
        self::assertSame($attendu, Totp::code(self::SECRET_RFC, Totp::pas($horodatage)));
    }

    public function testVerifierAccepteLeCodeCourantEtUnPasDeDecalage(): void
    {
        $t = 1_800_000_000;
        $pas = Totp::pas($t);
        self::assertSame($pas, Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas), $t));
        self::assertSame($pas - 1, Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas - 1), $t));
        self::assertSame($pas + 1, Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas + 1), $t));
        self::assertNull(Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas - 2), $t));
    }

    public function testVerifierToleraLesEspaces(): void
    {
        $t = 1_800_000_000;
        $code = Totp::code(self::SECRET_RFC, Totp::pas($t));
        self::assertNotNull(Totp::verifier(self::SECRET_RFC, substr($code, 0, 3) . ' ' . substr($code, 3), $t));
    }

    public function testVerifierRefuseLeRejeu(): void
    {
        $t = 1_800_000_000;
        $pas = Totp::pas($t);
        $code = Totp::code(self::SECRET_RFC, $pas);
        self::assertNull(Totp::verifier(self::SECRET_RFC, $code, $t, $pas));
        self::assertNull(Totp::verifier(self::SECRET_RFC, $code, $t, $pas + 1));
        self::assertSame($pas, Totp::verifier(self::SECRET_RFC, $code, $t, $pas - 1));
    }

    public function testVerifierRefuseLesSaisiesMalFormees(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12345a'] as $saisie) {
            self::assertNull(Totp::verifier(self::SECRET_RFC, $saisie, 1_800_000_000), $saisie);
        }
    }

    public function testSecretGenereFait160Bits(): void
    {
        $secret = Totp::genererSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertSame(20, strlen(Totp::base32Decoder($secret)));
        self::assertNotSame($secret, Totp::genererSecret());
    }

    public function testUri(): void
    {
        $uri = Totp::uri('ABCD', 'jean', 'EPIClub Mon:Club');
        self::assertSame(
            'otpauth://totp/EPIClub%20MonClub:jean?secret=ABCD&issuer=EPIClub%20MonClub&algorithm=SHA1&digits=6&period=30',
            $uri
        );
    }

    public function testBase32InvalideRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decoder('ABC1');
    }
}
