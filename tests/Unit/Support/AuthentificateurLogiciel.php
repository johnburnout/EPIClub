<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Support;

/**
 * [migration 020] Authentificateur WebAuthn logiciel (ES256, attestation
 * « none ») pour les tests : produit les réponses que renverrait
 * navigator.credentials.create() / get(), encodées en base64url.
 */
final class AuthentificateurLogiciel
{
    private \OpenSSLAsymmetricKey $cle;
    public readonly string $credentialId;
    private int $compteur = 0;

    public function __construct(private string $rpId, private string $origine, int $compteurInitial = 0)
    {
        $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($cle === false) {
            throw new \RuntimeException('openssl indisponible');
        }
        $this->cle = $cle;
        $this->credentialId = random_bytes(32);
        $this->compteur = $compteurInitial;
    }

    /** @return array{clientDataJSON: string, attestationObject: string} */
    public function creer(string $challenge, ?string $origine = null): array
    {
        $details = openssl_pkey_get_details($this->cle);
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // Clé COSE : {1: 2 (EC2), 3: -7 (ES256), -1: 1 (P-256), -2: x, -3: y}
        $cose = self::carte([[1, 2], [3, -7], [-1, 1], [-2, self::octets($x)], [-3, self::octets($y)]]);

        $authData = hash('sha256', $this->rpId, true)
            . chr(0x41 | 0x04)                // UP + UV + AT
            . pack('N', $this->compteur)
            . str_repeat("\0", 16)            // AAGUID
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . $cose;

        $attestation = self::carte([
            [self::texte('fmt'), self::texte('none')],
            [self::texte('attStmt'), self::carte([])],
            [self::texte('authData'), self::octets($authData)],
        ]);

        return [
            'clientDataJSON'    => self::b64(self::clientData('webauthn.create', $challenge, $origine ?? $this->origine)),
            'attestationObject' => self::b64($attestation),
        ];
    }

    /** @return array{id: string, clientDataJSON: string, authenticatorData: string, signature: string} */
    public function signer(string $challenge, bool $verifie = true, ?string $origine = null): array
    {
        $this->compteur++;
        $clientData = self::clientData('webauthn.get', $challenge, $origine ?? $this->origine);
        $authData = hash('sha256', $this->rpId, true)
            . chr($verifie ? 0x05 : 0x01)
            . pack('N', $this->compteur);
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->cle, OPENSSL_ALGO_SHA256);

        return [
            'id'                => self::b64($this->credentialId),
            'clientDataJSON'    => self::b64($clientData),
            'authenticatorData' => self::b64($authData),
            'signature'         => self::b64($signature),
        ];
    }

    public static function b64(string $binaire): string
    {
        return rtrim(strtr(base64_encode($binaire), '+/', '-_'), '=');
    }

    private static function clientData(string $type, string $challenge, string $origine): string
    {
        return (string) json_encode(['type' => $type, 'challenge' => self::b64($challenge), 'origin' => $origine, 'crossOrigin' => false]);
    }

    // --- CBOR minimal ---------------------------------------------------

    private static function entete(int $type, int $valeur): string
    {
        $type <<= 5;
        if ($valeur < 24) {
            return chr($type | $valeur);
        }
        if ($valeur < 256) {
            return chr($type | 24) . chr($valeur);
        }
        return chr($type | 25) . pack('n', $valeur);
    }

    private static function encoder(int|string $valeur): string
    {
        if (is_int($valeur)) {
            return $valeur >= 0 ? self::entete(0, $valeur) : self::entete(1, -1 - $valeur);
        }
        return $valeur; // déjà encodé
    }

    private static function octets(string $binaire): string
    {
        return self::entete(2, strlen($binaire)) . $binaire;
    }

    private static function texte(string $texte): string
    {
        return self::entete(3, strlen($texte)) . $texte;
    }

    /** @param list<array{int|string, int|string}> $paires */
    private static function carte(array $paires): string
    {
        $resultat = self::entete(5, count($paires));
        foreach ($paires as [$cle, $valeur]) {
            $resultat .= self::encoder($cle) . self::encoder($valeur);
        }
        return $resultat;
    }
}
