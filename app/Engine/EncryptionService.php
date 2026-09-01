<?php

namespace Epiclub\Engine;

class EncryptionService
{
    private ?string $secretKey;
    private string $cipherMethod;

    public function __construct()
    {
        $config = include __DIR__ . '/../../.env.local.php';
        $this->secretKey = isset($config['SECRET_KEY']) ? hex2bin($config['SECRET_KEY']) : null;
        $this->cipherMethod = $config['CIPHER_METHOD'] ?? 'AES-256-CBC';
    }

    public function decrypt(?string $encrypted): ?string
    {
        if (empty($encrypted) || !$this->secretKey) {
            return $encrypted;
        }

        $data = base64_decode($encrypted, true);
        if ($data === false) {
            return $encrypted;
        }

        $ivLength = openssl_cipher_iv_length($this->cipherMethod);
        if ($ivLength === false || strlen($data) < $ivLength) {
            return $encrypted;
        }

        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);
        $decrypted = openssl_decrypt($ciphertext, $this->cipherMethod, $this->secretKey, 0, $iv);

        return $decrypted === false ? $encrypted : $decrypted;
    }

    public function encrypt(string $plaintext): string
    {
        if (!$this->secretKey) {
            return $plaintext;
        }

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->cipherMethod));
        $encrypted = openssl_encrypt($plaintext, $this->cipherMethod, $this->secretKey, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
}