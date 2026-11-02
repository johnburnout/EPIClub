<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

/**
 * [migration 020] Stockage de la double authentification : application
 * d'authentification (colonnes utilisateur.totp_*), codes de secours
 * (utilisateur.codes_secours) et passkeys (table utilisateur_passkey).
 *
 * Ces colonnes ne sont JAMAIS exposées : absentes de
 * UtilisateurManager::PUBLIC_FIELDS et exclues de la session
 * (AppUserAuthenticator::SESSION_EXCLUDED_FIELDS).
 */
class DoubleAuthentificationManager extends AbstractManager
{
    /** Nombre de codes de secours générés à la fois. */
    public const NOMBRE_CODES_SECOURS = 10;

    /** Lettres et chiffres sans ambiguïté de lecture (ni 0/O, ni 1/l/I). */
    private const ALPHABET_CODES = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * @return array{totp_secret: ?string, totp_active_le: ?string, totp_dernier_pas: ?int, codes_secours: list<string>}|null
     */
    public function etat(int $utilisateurId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT totp_secret, totp_active_le, totp_dernier_pas, codes_secours FROM utilisateur WHERE id = :id'
        );
        $stmt->execute(['id' => $utilisateurId]);
        $ligne = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($ligne)) {
            return null;
        }
        return [
            'totp_secret'      => $ligne['totp_secret'] ?? null,
            'totp_active_le'   => $ligne['totp_active_le'] ?? null,
            'totp_dernier_pas' => isset($ligne['totp_dernier_pas']) ? (int) $ligne['totp_dernier_pas'] : null,
            'codes_secours'    => self::decoderCodes($ligne['codes_secours'] ?? null),
        ];
    }

    /**
     * Un second facteur est-il configuré (application ou passkey) ?
     * Lu à chaque connexion par mot de passe.
     */
    public function estConfiguree(int $utilisateurId): bool
    {
        $etat = $this->etat($utilisateurId);
        if ($etat === null) {
            return false;
        }
        return $etat['totp_active_le'] !== null || $this->compterPasskeys($utilisateurId) > 0;
    }

    // ==================================================================
    // Application d'authentification (TOTP)
    // ==================================================================

    public function activerTotp(int $utilisateurId, string $secretChiffre, int $pas): void
    {
        $stmt = $this->db->prepare(
            'UPDATE utilisateur SET totp_secret = :secret, totp_active_le = NOW(), totp_dernier_pas = :pas WHERE id = :id'
        );
        $stmt->execute(['secret' => $secretChiffre, 'pas' => $pas, 'id' => $utilisateurId]);
    }

    public function desactiverTotp(int $utilisateurId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE utilisateur SET totp_secret = NULL, totp_active_le = NULL, totp_dernier_pas = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $utilisateurId]);
    }

    /**
     * Mémorise le pas de temps d'un code accepté — atomique : false si un
     * pas égal ou postérieur a déjà été accepté (rejeu, ou deux requêtes
     * simultanées avec le même code).
     */
    public function consommerPasTotp(int $utilisateurId, int $pas): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE utilisateur SET totp_dernier_pas = :pas
              WHERE id = :id AND (totp_dernier_pas IS NULL OR totp_dernier_pas < :pas_min)'
        );
        $stmt->execute(['pas' => $pas, 'pas_min' => $pas, 'id' => $utilisateurId]);
        return $stmt->rowCount() === 1;
    }

    // ==================================================================
    // Codes de secours
    // ==================================================================

    /**
     * Remplace les codes de secours de l'utilisateur.
     *
     * @return list<string> Les codes EN CLAIR, à montrer une seule fois.
     */
    public function genererCodesSecours(int $utilisateurId): array
    {
        $codes = [];
        $hachages = [];
        for ($i = 0; $i < self::NOMBRE_CODES_SECOURS; $i++) {
            $code = self::nouveauCode();
            $codes[] = $code;
            $hachages[] = password_hash(self::normaliserCode($code), PASSWORD_DEFAULT);
        }
        $this->ecrireCodes($utilisateurId, $hachages);
        return $codes;
    }

    /**
     * Utilise un code de secours : true s'il était valide (il est alors
     * supprimé), false sinon.
     */
    public function consommerCodeSecours(int $utilisateurId, string $saisie): bool
    {
        $saisie = self::normaliserCode($saisie);
        if (strlen($saisie) !== 10) {
            return false;
        }
        $etat = $this->etat($utilisateurId);
        if ($etat === null) {
            return false;
        }
        foreach ($etat['codes_secours'] as $index => $hachage) {
            if (password_verify($saisie, $hachage)) {
                $restants = $etat['codes_secours'];
                unset($restants[$index]);
                // Écriture conditionnelle : un code ne sert qu'une fois,
                // même avec deux requêtes simultanées.
                $stmt = $this->db->prepare(
                    'UPDATE utilisateur SET codes_secours = :nouveaux WHERE id = :id AND codes_secours = :anciens'
                );
                $stmt->execute([
                    'nouveaux' => json_encode(array_values($restants)),
                    'anciens'  => json_encode($etat['codes_secours']),
                    'id'       => $utilisateurId,
                ]);
                return $stmt->rowCount() === 1;
            }
        }
        return false;
    }

    public function supprimerCodesSecours(int $utilisateurId): void
    {
        $this->ecrireCodes($utilisateurId, null);
    }

    /** Format affiché : « abcde-fghjk » (10 caractères, ≈ 50 bits). */
    public static function nouveauCode(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET_CODES) - 1;
        for ($i = 0; $i < 10; $i++) {
            $code .= self::ALPHABET_CODES[random_int(0, $max)];
        }
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    /** Saisie tolérante : casse, tirets et espaces ignorés. */
    public static function normaliserCode(string $code): string
    {
        return strtolower(preg_replace('/[\s\-]+/', '', $code) ?? '');
    }

    /** @param list<string>|null $hachages */
    private function ecrireCodes(int $utilisateurId, ?array $hachages): void
    {
        $stmt = $this->db->prepare('UPDATE utilisateur SET codes_secours = :codes WHERE id = :id');
        $stmt->execute([
            'codes' => $hachages === null ? null : json_encode($hachages),
            'id'    => $utilisateurId,
        ]);
    }

    /** @return list<string> */
    private static function decoderCodes(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $codes = json_decode($json, true);
        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    // ==================================================================
    // Passkeys
    // ==================================================================

    /** @return list<array{id:int, nom:string, date_creation:string, derniere_utilisation:?string}> */
    public function passkeys(int $utilisateurId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nom, date_creation, derniere_utilisation
               FROM utilisateur_passkey WHERE utilisateur_id = :id ORDER BY date_creation, id'
        );
        $stmt->execute(['id' => $utilisateurId]);
        return array_map(static fn (array $l) => [
            'id'                   => (int) $l['id'],
            'nom'                  => (string) $l['nom'],
            'date_creation'        => (string) $l['date_creation'],
            'derniere_utilisation' => $l['derniere_utilisation'] ?? null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<string> Identifiants base64url des passkeys de l'utilisateur. */
    public function identifiantsPasskeys(int $utilisateurId): array
    {
        $stmt = $this->db->prepare('SELECT credential_id FROM utilisateur_passkey WHERE utilisateur_id = :id');
        $stmt->execute(['id' => $utilisateurId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function compterPasskeys(int $utilisateurId): int
    {
        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM utilisateur_passkey WHERE utilisateur_id = :id');
            $stmt->execute(['id' => $utilisateurId]);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            // Table absente (migration 020 pas encore jouée) : aucune
            // passkey. Toute autre erreur remonte (pas de connexion
            // « au rabais » sur une base en panne).
            if ($e->getCode() === '42S02') {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * @return array{id:int, utilisateur_id:int, credential_id:string, cle_publique:string, compteur:int}|null
     */
    public function passkeyParIdentifiant(string $credentialId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, utilisateur_id, credential_id, cle_publique, compteur
               FROM utilisateur_passkey WHERE credential_id = :cid'
        );
        $stmt->execute(['cid' => $credentialId]);
        $l = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($l)) {
            return null;
        }
        return [
            'id'             => (int) $l['id'],
            'utilisateur_id' => (int) $l['utilisateur_id'],
            'credential_id'  => (string) $l['credential_id'],
            'cle_publique'   => (string) $l['cle_publique'],
            'compteur'       => (int) $l['compteur'],
        ];
    }

    public function ajouterPasskey(int $utilisateurId, string $credentialId, string $clePublique, int $compteur, string $nom): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO utilisateur_passkey (utilisateur_id, credential_id, cle_publique, compteur, nom, date_creation)
             VALUES (:uid, :cid, :cle, :compteur, :nom, NOW())'
        );
        $stmt->execute([
            'uid'      => $utilisateurId,
            'cid'      => $credentialId,
            'cle'      => $clePublique,
            'compteur' => $compteur,
            'nom'      => $nom,
        ]);
    }

    public function passkeyUtilisee(int $passkeyId, int $compteur): void
    {
        $stmt = $this->db->prepare(
            'UPDATE utilisateur_passkey SET compteur = :compteur, derniere_utilisation = NOW() WHERE id = :id'
        );
        $stmt->execute(['compteur' => $compteur, 'id' => $passkeyId]);
    }

    /** Supprime une passkey de l'utilisateur ; false si elle ne lui appartient pas. */
    public function supprimerPasskey(int $utilisateurId, int $passkeyId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM utilisateur_passkey WHERE id = :id AND utilisateur_id = :uid');
        $stmt->execute(['id' => $passkeyId, 'uid' => $utilisateurId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Retire tous les facteurs (application, codes de secours, passkeys) —
     * réinitialisation par un super-administrateur.
     */
    public function reinitialiser(int $utilisateurId): void
    {
        $this->desactiverTotp($utilisateurId);
        $this->supprimerCodesSecours($utilisateurId);
        $stmt = $this->db->prepare('DELETE FROM utilisateur_passkey WHERE utilisateur_id = :id');
        $stmt->execute(['id' => $utilisateurId]);
    }

    protected function tableName(): string
    {
        return 'utilisateur_passkey';
    }
}
