<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [VAGUE 13 - #50b] Service de chiffrement symétrique centralisé.
 *
 * [SÉCURITÉ — audit 2026-10-21] Chiffrement authentifié AES-256-GCM.
 *
 * ─── Format v2 (écrit par encrypt()) ───
 *   "v2:" . base64( nonce(12) || tag(16) || ciphertext )
 *   - AES-256-GCM, nonce aléatoire de 12 octets, tag de 16 octets
 *   - AAD = "epiclub:v2" (lie le ciphertext au format)
 *   - clé GCM = HKDF-SHA256(hex2bin(SECRET_KEY), info "epiclub:aes-256-gcm")
 *     → clé distincte de celle du format legacy (séparation des usages)
 *   Toute altération du ciphertext, du nonce ou du tag est détectée :
 *   decrypt() retourne alors null.
 *
 * ─── Format legacy v1 (lecture seule) ───
 *   base64( IV || ciphertext ), CIPHER_METHOD (AES-256-CBC), sans HMAC.
 *   Encore déchiffré pour les remarques écrites avant v2 ; jamais produit.
 *   Le préfixe "v2:" ne peut pas apparaître dans du base64 (« : » hors
 *   alphabet) : aucune ambiguïté entre les deux formats.
 *
 * ─── Politique stricte (aligné #52) ───
 *   - SECRET_KEY absente ou invalide → RuntimeException au constructeur.
 *   - decrypt() retourne null si la valeur n'est pas un ciphertext valide
 *     (legacy en clair, base64 corrompu, mauvaise clé, payload trop court,
 *     ciphertext altéré).
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
    public const PREFIX_V2 = 'v2:';

    private const GCM_CIPHER     = 'aes-256-gcm';
    private const GCM_NONCE_LEN  = 12;
    private const GCM_TAG_LEN    = 16;
    private const GCM_AAD        = 'epiclub:v2';
    private const GCM_HKDF_INFO  = 'epiclub:aes-256-gcm';

    /** Clé brute (format legacy CBC). */
    private string $secretKey;
    /** Clé dérivée (format v2 GCM). */
    private string $gcmKey;
    /** Algorithme du format legacy (lecture seule). */
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
        $this->gcmKey = hash_hkdf('sha256', $key, 32, self::GCM_HKDF_INFO);

        $cipher = $config->get('CIPHER_METHOD', 'AES-256-CBC');
        if (!is_string($cipher) || $cipher === '') {
            $cipher = 'AES-256-CBC';
        }
        $this->cipherMethod = $cipher;
    }

    /**
     * Déchiffre une valeur (format v2 GCM, ou legacy CBC).
     *
     * @return string|null null si la valeur est vide ou n'est pas un
     *                     ciphertext valide (legacy en clair, base64
     *                     corrompu, mauvaise clé, payload trop court,
     *                     ciphertext altéré).
     */
    public function decrypt(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        if (str_starts_with($encrypted, self::PREFIX_V2)) {
            return $this->decryptV2(substr($encrypted, strlen(self::PREFIX_V2)));
        }

        return $this->decryptLegacy($encrypted);
    }

    /**
     * Chiffre une valeur (format v2, AES-256-GCM).
     * Retourne toujours un ciphertext non vide.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::GCM_NONCE_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::GCM_CIPHER,
            $this->gcmKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::GCM_AAD,
            self::GCM_TAG_LEN
        );
        if ($ciphertext === false || strlen($tag) !== self::GCM_TAG_LEN) {
            throw new \RuntimeException('openssl_encrypt failed.');
        }

        return self::PREFIX_V2 . base64_encode($nonce . $tag . $ciphertext);
    }

    private function decryptV2(string $encoded): ?string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < self::GCM_NONCE_LEN + self::GCM_TAG_LEN) {
            return null;
        }

        $nonce = substr($data, 0, self::GCM_NONCE_LEN);
        $tag = substr($data, self::GCM_NONCE_LEN, self::GCM_TAG_LEN);
        $ciphertext = substr($data, self::GCM_NONCE_LEN + self::GCM_TAG_LEN);

        $decrypted = openssl_decrypt(
            $ciphertext,
            self::GCM_CIPHER,
            $this->gcmKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::GCM_AAD
        );

        return $decrypted === false ? null : $decrypted;
    }

    /**
     * Format v1 : base64(IV || ciphertext) en CIPHER_METHOD. Lecture seule.
     */
    private function decryptLegacy(string $encrypted): ?string
    {
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