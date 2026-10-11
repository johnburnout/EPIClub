<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\EncryptionService;
use PHPUnit\Framework\TestCase;

final class EncryptionServiceTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        // Fixture avec SECRET_KEY + CIPHER_METHOD
        $this->envFile = __DIR__ . '/../../Fixtures/env.test.php';
        if (!is_file($this->envFile)) {
            self::markTestSkipped('Fixture env.test.php introuvable.');
        }
    }

    private function service(): EncryptionService
    {
        return new EncryptionService(new ConfigProvider($this->envFile));
    }

    // ==================================================================
    // Round-trip
    // ==================================================================

    public function testEncryptDecryptRoundTrip(): void
    {
        $plain = 'Remarque secrète 123';
        $encrypted = $this->service()->encrypt($plain);

        self::assertNotSame($plain, $encrypted, 'Le ciphertext ne doit pas être le plaintext.');
        self::assertSame($plain, $this->service()->decrypt($encrypted));
    }

    public function testEncryptProducesDifferentCiphertextsForSameInput(): void
    {
        // IV aléatoire → 2 chiffrements du même plaintext diffèrent
        $a = $this->service()->encrypt('same');
        $b = $this->service()->encrypt('same');
        self::assertNotSame($a, $b);
        self::assertSame('same', $this->service()->decrypt($a));
        self::assertSame('same', $this->service()->decrypt($b));
    }

    // ==================================================================
    // decrypt() — cas dégradés (retournent null, pas l'input)
    // ==================================================================

    public function testDecryptReturnsNullForEmptyInput(): void
    {
        self::assertNull($this->service()->decrypt(null));
        self::assertNull($this->service()->decrypt(''));
    }

    public function testDecryptReturnsNullForInvalidBase64(): void
    {
        self::assertNull($this->service()->decrypt('not-base64-!!!'));
    }

    public function testDecryptReturnsNullForTooShortData(): void
    {
        // base64 valide mais payload trop court (moins que la taille IV)
        self::assertNull($this->service()->decrypt(base64_encode('short')));
    }

    public function testDecryptReturnsNullForPlaintextLegacyValue(): void
    {
        // Une valeur en clair (legacy pré-#52) n'est pas un ciphertext valide
        self::assertNull($this->service()->decrypt('Remarque en clair legacy'));
    }

    // ==================================================================
    // encrypt() — cas dégradés
    // ==================================================================

    public function testEncryptEmptyStringReturnsCiphertext(): void
    {
        // encrypt('') produit un ciphertext valide (IV seul + ciphertext vide)
        $encrypted = $this->service()->encrypt('');
        self::assertNotEmpty($encrypted);
        self::assertSame('', $this->service()->decrypt($encrypted));
    }

    // ==================================================================
    // isEncrypted()
    // ==================================================================

    public function testIsEncryptedReturnsTrueForValidCiphertext(): void
    {
        $encrypted = $this->service()->encrypt('hello');
        self::assertTrue($this->service()->isEncrypted($encrypted));
    }

    public function testIsEncryptedReturnsFalseForPlaintext(): void
    {
        self::assertFalse($this->service()->isEncrypted('hello'));
    }

    public function testIsEncryptedReturnsFalseForNullAndEmpty(): void
    {
        self::assertFalse($this->service()->isEncrypted(null));
        self::assertFalse($this->service()->isEncrypted(''));
    }

    // ==================================================================
    // Politique stricte : SECRET_KEY absente → RuntimeException
    // ==================================================================

    public function testConstructorThrowsWhenSecretKeyMissing(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'env_') . '.php';
        file_put_contents($tmpFile, "<?php\nreturn ['CIPHER_METHOD' => 'AES-256-CBC'];\n");

        try {
            $this->expectException(\RuntimeException::class);
            new EncryptionService(new ConfigProvider($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testConstructorThrowsWhenSecretKeyNotHex(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'env_') . '.php';
        file_put_contents($tmpFile, "<?php\nreturn ['SECRET_KEY' => 'not-hex-!!'];\n");

        try {
            $this->expectException(\RuntimeException::class);
            new EncryptionService(new ConfigProvider($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }
}