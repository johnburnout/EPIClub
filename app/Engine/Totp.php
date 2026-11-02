<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [migration 020] Mots de passe à usage unique basés sur le temps
 * (TOTP, RFC 6238) : codes à 6 chiffres renouvelés toutes les 30
 * secondes, HMAC-SHA1 — les paramètres par défaut que toutes les
 * applications d'authentification comprennent (Google Authenticator,
 * Microsoft Authenticator, Aegis, FreeOTP, 2FAS, Bitwarden…).
 *
 * Classe pure (sans base ni session) : l'horloge est passée en
 * paramètre pour les tests.
 */
final class Totp
{
    public const CHIFFRES = 6;
    public const PERIODE = 30;

    /** Décalage d'horloge toléré, en pas de 30 s (± 30 s). */
    public const FENETRE = 1;

    private const ALPHABET_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secret aléatoire de 160 bits, encodé en base32 (32 caractères). */
    public static function genererSecret(): string
    {
        return self::base32Encoder(random_bytes(20));
    }

    /** Pas de temps (compteur RFC 6238) correspondant à un horodatage. */
    public static function pas(int $horodatage): int
    {
        return intdiv($horodatage, self::PERIODE);
    }

    /** Code attendu pour un pas de temps donné. */
    public static function code(string $secretBase32, int $pas): string
    {
        $cle = self::base32Decoder($secretBase32);
        $compteur = pack('J', $pas);
        $hmac = hash_hmac('sha1', $compteur, $cle, true);

        $decalage = ord($hmac[19]) & 0x0F;
        $valeur = ((ord($hmac[$decalage]) & 0x7F) << 24)
            | (ord($hmac[$decalage + 1]) << 16)
            | (ord($hmac[$decalage + 2]) << 8)
            | ord($hmac[$decalage + 3]);

        return str_pad((string) ($valeur % (10 ** self::CHIFFRES)), self::CHIFFRES, '0', STR_PAD_LEFT);
    }

    /**
     * Vérifie un code saisi. Retourne le pas de temps reconnu (à
     * mémoriser pour refuser le rejeu), ou null si le code est faux, mal
     * formé ou déjà utilisé (pas ≤ $dernierPasAccepte).
     */
    public static function verifier(string $secretBase32, string $saisie, int $horodatage, ?int $dernierPasAccepte = null): ?int
    {
        $saisie = preg_replace('/\s+/', '', $saisie) ?? '';
        if (preg_match('/^\d{' . self::CHIFFRES . '}$/', $saisie) !== 1) {
            return null;
        }

        $courant = self::pas($horodatage);
        $reconnu = null;
        // Toute la fenêtre est parcourue (temps constant vis-à-vis du pas
        // reconnu).
        for ($ecart = -self::FENETRE; $ecart <= self::FENETRE; $ecart++) {
            $pas = $courant + $ecart;
            if (hash_equals(self::code($secretBase32, $pas), $saisie)) {
                $reconnu ??= $pas;
            }
        }

        if ($reconnu === null || ($dernierPasAccepte !== null && $reconnu <= $dernierPasAccepte)) {
            return null;
        }
        return $reconnu;
    }

    /**
     * URI « otpauth:// » lue par les applications (QR code).
     *
     * @see https://github.com/google/google-authenticator/wiki/Key-Uri-Format
     */
    public static function uri(string $secretBase32, string $compte, string $emetteur): string
    {
        $emetteur = str_replace(':', '', $emetteur);
        $compte = str_replace(':', '', $compte);

        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($emetteur),
            rawurlencode($compte),
            http_build_query([
                'secret'    => $secretBase32,
                'issuer'    => $emetteur,
                'algorithm' => 'SHA1',
                'digits'    => self::CHIFFRES,
                'period'    => self::PERIODE,
            ], '', '&', PHP_QUERY_RFC3986)
        );
    }

    /** Secret groupé par 4 caractères, pour la saisie manuelle. */
    public static function formaterPourSaisie(string $secretBase32): string
    {
        return trim(chunk_split($secretBase32, 4, ' '));
    }

    public static function base32Encoder(string $binaire): string
    {
        $bits = '';
        foreach (str_split($binaire) as $octet) {
            $bits .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
        }
        $resultat = '';
        foreach (str_split($bits, 5) as $bloc) {
            $resultat .= self::ALPHABET_BASE32[bindec(str_pad($bloc, 5, '0'))];
        }
        return $resultat;
    }

    /**
     * @throws \InvalidArgumentException si la chaîne n'est pas du base32
     */
    public static function base32Decoder(string $base32): string
    {
        $base32 = strtoupper(str_replace([' ', '='], '', $base32));
        if ($base32 === '' || strspn($base32, self::ALPHABET_BASE32) !== strlen($base32)) {
            throw new \InvalidArgumentException('Secret TOTP invalide.');
        }
        $bits = '';
        foreach (str_split($base32) as $caractere) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET_BASE32, $caractere)), 5, '0', STR_PAD_LEFT);
        }
        $binaire = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) === 8) {
                $binaire .= chr(bindec($octet));
            }
        }
        return $binaire;
    }
}
