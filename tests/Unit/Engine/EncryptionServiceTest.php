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

    // ==================================================================
    // [SÉCURITÉ — audit 2026-10-21] Format v2 AES-256-GCM authentifié
    // ==================================================================

    public function testEncryptProduitLeFormatV2(): void
    {
        $encrypted = $this->service()->encrypt('hello');

        self::assertStringStartsWith(EncryptionService::PREFIX_V2, $encrypted);
        $data = base64_decode(substr($encrypted, strlen(EncryptionService::PREFIX_V2)), true);
        self::assertIsString($data);
        self::assertSame(12 + 16 + strlen('hello'), strlen($data), 'nonce + tag + ciphertext');
    }

    public function testCiphertextAltereEstRejete(): void
    {
        $encrypted = $this->service()->encrypt('Remarque secrète');
        $data = base64_decode(substr($encrypted, strlen(EncryptionService::PREFIX_V2)), true);
        self::assertIsString($data);

        // Un bit inversé dans le nonce, le tag puis le ciphertext
        foreach ([0, 12, strlen($data) - 1] as $position) {
            $altere = $data;
            $altere[$position] = chr(ord($altere[$position]) ^ 0x01);
            self::assertNull(
                $this->service()->decrypt(EncryptionService::PREFIX_V2 . base64_encode($altere)),
                "altération à l'octet $position non détectée"
            );
        }
    }

    public function testPayloadV2TropCourtEstRejete(): void
    {
        self::assertNull($this->service()->decrypt(EncryptionService::PREFIX_V2 . base64_encode(str_repeat("\0", 27))));
        self::assertNull($this->service()->decrypt(EncryptionService::PREFIX_V2 . 'pas-du-base64-!!!'));
    }

    public function testMauvaiseCleEstRejetee(): void
    {
        $encrypted = $this->service()->encrypt('hello');

        $tmpFile = tempnam(sys_get_temp_dir(), 'env_') . '.php';
        file_put_contents($tmpFile, "<?php\nreturn ['SECRET_KEY' => '" . str_repeat('cd', 32) . "'];\n");
        try {
            self::assertNull((new EncryptionService(new ConfigProvider($tmpFile)))->decrypt($encrypted));
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testLeFormatLegacyCbcResteLisible(): void
    {
        // Remarque chiffrée avant v2 : base64(IV || AES-256-CBC), clé brute
        $key = hex2bin(str_repeat('ab', 32));
        $iv = random_bytes(16);
        $legacy = base64_encode($iv . openssl_encrypt('Remarque v1', 'AES-256-CBC', $key, 0, $iv));

        self::assertSame('Remarque v1', $this->service()->decrypt($legacy));
        self::assertTrue($this->service()->isEncrypted($legacy));
    }
}
