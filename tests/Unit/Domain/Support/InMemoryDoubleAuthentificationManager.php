<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain\Support;

use Epiclub\Domain\DoubleAuthentificationManager;

/**
 * [migration 020] DoubleAuthentificationManager sans MySQL (stockage en
 * mémoire), mêmes règles : rejeu TOTP refusé, codes de secours à usage
 * unique, passkey supprimable par son seul propriétaire.
 */
final class InMemoryDoubleAuthentificationManager extends DoubleAuthentificationManager
{
    /** @var array<int, array{totp_secret: ?string, totp_active_le: ?string, totp_dernier_pas: ?int, codes_secours: list<string>}> */
    public array $etats = [];
    /** @var array<int, array{id:int, utilisateur_id:int, credential_id:string, cle_publique:string, compteur:int, nom:string, date_creation:string, derniere_utilisation:?string}> */
    public array $passkeys = [];
    private int $prochainId = 1;

    public function __construct()
    {
        // Ne PAS appeler parent::__construct() : connexion MySQL.
    }

    private function &ligne(int $id): array
    {
        $this->etats[$id] ??= ['totp_secret' => null, 'totp_active_le' => null, 'totp_dernier_pas' => null, 'codes_secours' => []];
        return $this->etats[$id];
    }

    public function etat(int $utilisateurId): ?array
    {
        return $this->ligne($utilisateurId);
    }

    public function activerTotp(int $utilisateurId, string $secretChiffre, int $pas): void
    {
        $l = &$this->ligne($utilisateurId);
        $l['totp_secret'] = $secretChiffre;
        $l['totp_active_le'] = '2026-11-02 10:00:00';
        $l['totp_dernier_pas'] = $pas;
    }

    public function desactiverTotp(int $utilisateurId): void
    {
        $l = &$this->ligne($utilisateurId);
        $l['totp_secret'] = $l['totp_active_le'] = $l['totp_dernier_pas'] = null;
    }

    public function consommerPasTotp(int $utilisateurId, int $pas): bool
    {
        $l = &$this->ligne($utilisateurId);
        if ($l['totp_dernier_pas'] !== null && $l['totp_dernier_pas'] >= $pas) {
            return false;
        }
        $l['totp_dernier_pas'] = $pas;
        return true;
    }

    public function genererCodesSecours(int $utilisateurId): array
    {
        $codes = [];
        $l = &$this->ligne($utilisateurId);
        $l['codes_secours'] = [];
        for ($i = 0; $i < self::NOMBRE_CODES_SECOURS; $i++) {
            $codes[] = $code = self::nouveauCode();
            // Coût minimal : tests rapides.
            $l['codes_secours'][] = password_hash(self::normaliserCode($code), PASSWORD_BCRYPT, ['cost' => 4]);
        }
        return $codes;
    }

    public function consommerCodeSecours(int $utilisateurId, string $saisie): bool
    {
        $l = &$this->ligne($utilisateurId);
        foreach ($l['codes_secours'] as $i => $hachage) {
            if (password_verify(self::normaliserCode($saisie), $hachage)) {
                unset($l['codes_secours'][$i]);
                $l['codes_secours'] = array_values($l['codes_secours']);
                return true;
            }
        }
        return false;
    }

    public function supprimerCodesSecours(int $utilisateurId): void
    {
        $this->ligne($utilisateurId)['codes_secours'] = [];
    }

    public function passkeys(int $utilisateurId): array
    {
        return array_values(array_map(
            fn (array $p) => ['id' => $p['id'], 'nom' => $p['nom'], 'date_creation' => $p['date_creation'], 'derniere_utilisation' => $p['derniere_utilisation']],
            array_filter($this->passkeys, fn (array $p) => $p['utilisateur_id'] === $utilisateurId)
        ));
    }

    public function identifiantsPasskeys(int $utilisateurId): array
    {
        return array_values(array_map(
            fn (array $p) => $p['credential_id'],
            array_filter($this->passkeys, fn (array $p) => $p['utilisateur_id'] === $utilisateurId)
        ));
    }

    public function compterPasskeys(int $utilisateurId): int
    {
        return count($this->identifiantsPasskeys($utilisateurId));
    }

    public function passkeyParIdentifiant(string $credentialId): ?array
    {
        foreach ($this->passkeys as $p) {
            if ($p['credential_id'] === $credentialId) {
                return array_intersect_key($p, array_flip(['id', 'utilisateur_id', 'credential_id', 'cle_publique', 'compteur']));
            }
        }
        return null;
    }

    public function ajouterPasskey(int $utilisateurId, string $credentialId, string $clePublique, int $compteur, string $nom): void
    {
        if ($this->passkeyParIdentifiant($credentialId) !== null) {
            throw new \PDOException('Duplicate entry');
        }
        $id = $this->prochainId++;
        $this->passkeys[$id] = [
            'id' => $id, 'utilisateur_id' => $utilisateurId, 'credential_id' => $credentialId,
            'cle_publique' => $clePublique, 'compteur' => $compteur, 'nom' => $nom,
            'date_creation' => '2026-11-02 10:00:00', 'derniere_utilisation' => null,
        ];
    }

    public function passkeyUtilisee(int $passkeyId, int $compteur): void
    {
        $this->passkeys[$passkeyId]['compteur'] = $compteur;
        $this->passkeys[$passkeyId]['derniere_utilisation'] = '2026-11-02 10:05:00';
    }

    public function supprimerPasskey(int $utilisateurId, int $passkeyId): bool
    {
        if (($this->passkeys[$passkeyId]['utilisateur_id'] ?? null) !== $utilisateurId) {
            return false;
        }
        unset($this->passkeys[$passkeyId]);
        return true;
    }

    public function reinitialiser(int $utilisateurId): void
    {
        unset($this->etats[$utilisateurId]);
        $this->passkeys = array_filter($this->passkeys, fn (array $p) => $p['utilisateur_id'] !== $utilisateurId);
    }
}
