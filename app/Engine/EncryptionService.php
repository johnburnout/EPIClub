<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [VAGUE 13 - #50b] Service de chiffrement symétrique centralisé.
 *
 * ─── Format du ciphertext stocké ───
 *   base64( IV || ciphertext )
 *   - IV de openssl_cipher_iv_length($cipher) octets aléatoires
 *   - ciphertext = openssl_encrypt($plain, $cipher, $key, 0, $iv)
 *   - clé = hex2bin(SECRET_KEY) — 32 bytes pour AES-256
 *
 * ─── Politique stricte (aligné #52) ───
 *   - SECRET_KEY absente ou invalide → RuntimeException au constructeur.
 *   - decrypt() retourne null si la valeur n'est pas un ciphertext valide
 *     (legacy en clair, base64 corrompu, mauvaise clé, payload trop court).
 *   - encrypt() retourne toujours un ciphertext non vide.
 *
 * ─── Injection ───
 *   Le service reçoit un ConfigProvider (Vague 12). Les appelants qui
 *   veulent rester testables passent par une factory protected
 *   `encryptionService()` (Vague 11).
 *
 * @see \Epiclub\Controller\ControleController Pour la délégation depuis le contrôleur.
 * @see \Epiclub\Domain\EquipementManager Pour la délégation depuis le manager.
 */
class EncryptionService
{
    private string $secretKey;
    private string $cipherMethod;

    /**
     * @throws \RuntimeException si SECRET_KEY est absente ou invalide.
     */
    public function __construct(ConfigProvider $config)
    {
        $keyHex = $config->get('SECRET_KEY');
        if (!is_string($keyHex) || $keyHex === '') {
            throw new \RuntimeException('SECRET_KEY is not configured.');
        }

        // [ROBUSTESSE] On valide AVANT hex2bin() pour éviter le warning PHP
        // (hex2bin émet un E_WARNING si l'input n'est pas hex, ce qui
        // pollue la sortie de test et masquerait d'autres warnings).
        if (strlen($keyHex) % 2 !== 0 || !ctype_xdigit($keyHex)) {
            throw new \RuntimeException('SECRET_KEY is not valid hex.');
        }
        
        $key = hex2bin($keyHex);
        if ($key === false) {
            // Ceinture+bretelles : ne devrait jamais arriver après la
            // validation ci-dessus, mais on garde pour la robustesse.
            throw new \RuntimeException('SECRET_KEY is not valid hex.');
        }
        $this->secretKey = $key;

        $cipher = $config->get('CIPHER_METHOD', 'AES-256-CBC');
        if (!is_string($cipher) || $cipher === '') {
            $cipher = 'AES-256-CBC';
        }
        $this->cipherMethod = $cipher;
    }

    /**
     * Déchiffre une valeur.
     *
     * @return string|null null si la valeur est vide ou n'est pas un
     *                     ciphertext valide (legacy en clair, base64
     *                     corrompu, mauvaise clé, payload trop court).
     */
    public function decrypt(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        $data = base64_decode($encrypted, true);
        if ($data === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length($this->cipherMethod);
        if ($ivLength === false || strlen($data) <= $ivLength) {
            return null;
        }

        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);
        $decrypted = openssl_decrypt($ciphertext, $this->cipherMethod, $this->secretKey, 0, $iv);

        return $decrypted === false ? null : $decrypted;
    }

    /**
     * Chiffre une valeur. Retourne toujours un ciphertext non vide.
     */
    public function encrypt(string $plaintext): string
    {
        $ivLength = openssl_cipher_iv_length($this->cipherMethod);
        if ($ivLength === false) {
            throw new \RuntimeException(
                'Invalid CIPHER_METHOD: ' . $this->cipherMethod
            );
        }

        $iv = openssl_random_pseudo_bytes($ivLength);
        $encrypted = openssl_encrypt($plaintext, $this->cipherMethod, $this->secretKey, 0, $iv);
        if ($encrypted === false) {
            throw new \RuntimeException('openssl_encrypt failed.');
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Détecte si une valeur est déjà chiffrée par ce service.
     *
     * Utilisé pour rester idempotent sur les contrôles déjà traités
     * par edit() après le fix #52.
     */
    public function isEncrypted(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        return $this->decrypt($value) !== null;
    }
}