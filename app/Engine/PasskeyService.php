<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * [migration 020] Passkeys (WebAuthn / FIDO2) : enregistrement et
 * vérification, adossés à la bibliothèque lbuchs/webauthn.
 *
 * Partie de confiance (« relying party ») : l'hôte de ROOT_URL. Une
 * passkey enregistrée pour https://epi.monclub.fr ne fonctionne donc que
 * sur ce domaine (changer ROOT_URL de domaine rend les passkeys
 * existantes inutilisables : elles doivent être réenregistrées).
 *
 * Attestation « none » : on n'exige aucun modèle d'authentificateur
 * particulier (clé de sécurité, téléphone, gestionnaire de mots de
 * passe), et aucune information sur l'appareil n'est demandée.
 *
 * Toutes les données binaires échangées avec le navigateur sont en
 * base64url.
 */
class PasskeyService
{
    /** Délai laissé au navigateur pour la cérémonie, en secondes. */
    public const DELAI = 120;

    private string $rpId;
    private string $origine;

    /**
     * @throws \RuntimeException si ROOT_URL est inexploitable
     */
    public function __construct(string $rootUrl, private string $nomApplication = 'EPIClub')
    {
        $parties = parse_url($rootUrl);
        $schema = strtolower((string) ($parties['scheme'] ?? ''));
        $hote = strtolower((string) ($parties['host'] ?? ''));
        if ($hote === '' || !in_array($schema, ['http', 'https'], true)) {
            throw new \RuntimeException('ROOT_URL invalide : passkeys indisponibles.');
        }
        // WebAuthn n'est autorisé qu'en HTTPS (sauf localhost, en développement).
        if ($schema !== 'https' && $hote !== 'localhost') {
            throw new \RuntimeException('Les passkeys nécessitent un site servi en HTTPS.');
        }
        $this->rpId = $hote;
        $this->origine = $schema . '://' . $hote . (isset($parties['port']) ? ':' . $parties['port'] : '');
    }

    public function rpId(): string
    {
        return $this->rpId;
    }

    /** Origine attendue (schéma, hôte, port de ROOT_URL). */
    public function origine(): string
    {
        return $this->origine;
    }

    /** Identifiant opaque (« user handle ») d'un utilisateur. */
    public static function identifiantUtilisateur(int $utilisateurId): string
    {
        return 'epiclub-utilisateur-' . $utilisateurId;
    }

    /** Utilisateur désigné par un « user handle », ou null. */
    public static function utilisateurDepuisIdentifiant(string $handle): ?int
    {
        if (preg_match('/^epiclub-utilisateur-([1-9]\d*)$/', $handle, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }

    /**
     * Options de navigator.credentials.create().
     *
     * @param list<string> $dejaEnregistrees identifiants base64url (pas de doublon sur un même appareil)
     * @return array{options: mixed, challenge: string} challenge binaire, à garder en session
     */
    public function optionsEnregistrement(int $utilisateurId, string $identifiant, string $nomAffiche, array $dejaEnregistrees): array
    {
        $webAuthn = $this->webAuthn();
        $args = $webAuthn->getCreateArgs(
            self::identifiantUtilisateur($utilisateurId),
            $identifiant,
            $nomAffiche,
            self::DELAI,
            'preferred',   // passkey « découvrable » si possible : connexion sans identifiant
            'preferred',
            null,
            array_map([self::class, 'decoderBase64Url'], $dejaEnregistrees)
        );
        $args->publicKey->attestation = 'none';
        unset($args->publicKey->extensions);

        return [
            'options'   => json_decode((string) json_encode($args), true),
            'challenge' => $webAuthn->getChallenge()->getBinaryString(),
        ];
    }

    /**
     * Vérifie la réponse de navigator.credentials.create().
     *
     * @return array{credential_id: string, cle_publique: string, compteur: int}
     * @throws \RuntimeException réponse invalide
     */
    public function verifierEnregistrement(string $clientDataJson, string $attestationObject, string $challenge): array
    {
        $clientData = $this->decoderClientData($clientDataJson);
        try {
            $donnees = $this->webAuthn()->processCreate(
                $clientData,
                self::decoderBase64Url($attestationObject),
                $challenge,
                false,
                true,
                false
            );
        } catch (WebAuthnException $e) {
            throw new \RuntimeException('Enregistrement de la passkey refusé : ' . $e->getMessage(), 0, $e);
        }

        if (!is_string($donnees->credentialId ?? null) || !is_string($donnees->credentialPublicKey ?? null)) {
            throw new \RuntimeException('Enregistrement de la passkey refusé : clé non prise en charge.');
        }

        return [
            'credential_id' => self::encoderBase64Url($donnees->credentialId),
            'cle_publique'  => $donnees->credentialPublicKey,
            'compteur'      => (int) ($donnees->signatureCounter ?? 0),
        ];
    }

    /**
     * Options de navigator.credentials.get().
     *
     * @param list<string> $autorisees identifiants base64url ; vide : passkeys découvrables
     *                                 (connexion sans identifiant)
     * @return array{options: mixed, challenge: string}
     */
    public function optionsConnexion(array $autorisees, bool $exigerVerification): array
    {
        $webAuthn = $this->webAuthn();
        $args = $webAuthn->getGetArgs(
            array_map([self::class, 'decoderBase64Url'], $autorisees),
            self::DELAI,
            true, true, true, true, true,
            $exigerVerification ? 'required' : 'preferred'
        );
        return [
            'options'   => json_decode((string) json_encode($args), true),
            'challenge' => $webAuthn->getChallenge()->getBinaryString(),
        ];
    }

    /**
     * Vérifie la réponse de navigator.credentials.get().
     *
     * @return int nouveau compteur de signatures
     * @throws \RuntimeException signature ou données invalides
     */
    public function verifierConnexion(
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
        string $clePublique,
        string $challenge,
        int $compteur,
        bool $exigerVerification,
    ): int {
        $clientData = $this->decoderClientData($clientDataJson);
        $webAuthn = $this->webAuthn();
        try {
            $webAuthn->processGet(
                $clientData,
                self::decoderBase64Url($authenticatorData),
                self::decoderBase64Url($signature),
                $clePublique,
                $challenge,
                $compteur,
                $exigerVerification,
                true
            );
        } catch (WebAuthnException $e) {
            throw new \RuntimeException('Passkey refusée : ' . $e->getMessage(), 0, $e);
        }
        return (int) ($webAuthn->getSignatureCounter() ?? $compteur);
    }

    /**
     * Décode clientDataJSON et vérifie l'origine EXACTE (schéma, hôte et
     * port de ROOT_URL) — la bibliothèque se contente d'un suffixe de
     * domaine.
     */
    private function decoderClientData(string $clientDataJson): string
    {
        $brut = self::decoderBase64Url($clientDataJson);
        $donnees = json_decode($brut, true);
        if (!is_array($donnees) || !is_string($donnees['origin'] ?? null)
            || strtolower(rtrim($donnees['origin'], '/')) !== $this->origine
        ) {
            throw new \RuntimeException('Passkey refusée : origine inattendue.');
        }
        return $brut;
    }

    protected function webAuthn(): WebAuthn
    {
        // true : données binaires en base64url dans le JSON.
        return new WebAuthn($this->nomApplication, $this->rpId, null, true);
    }

    public static function encoderBase64Url(string $binaire): string
    {
        return rtrim(strtr(base64_encode($binaire), '+/', '-_'), '=');
    }

    /** @throws \RuntimeException */
    public static function decoderBase64Url(string $texte): string
    {
        if ($texte === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $texte) !== 1) {
            throw new \RuntimeException('Donnée de passkey mal formée.');
        }
        $binaire = base64_decode(strtr($texte, '-_', '+/'), true);
        if ($binaire === false) {
            throw new \RuntimeException('Donnée de passkey mal formée.');
        }
        return $binaire;
    }
}
