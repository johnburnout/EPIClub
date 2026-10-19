<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

/**
 * [SÉCURITÉ — audit 2026-10-19 S2] Limitation progressive des tentatives
 * (connexion, mot de passe oublié) contre la force brute.
 *
 * Politique, pour une clé donnée (ex. « login-user:jdupont ») :
 *   - les échecs sont comptés sur une fenêtre glissante de 24 h
 *     (remise à zéro si le dernier échec date de plus de 24 h) ;
 *   - à partir du seuil, chaque nouvel échec bloque la clé pendant
 *     1 min, puis 2, 4, 8… plafonné à 1 h ;
 *   - un succès remet la clé à zéro (reinitialiser()).
 *
 * Les clés sont hachées avant stockage (pas d'identifiant ni d'IP en
 * clair en base). La logique (méthodes publiques) est séparée du
 * stockage (méthodes protected lire/ecrire/supprimer/purger), que la
 * suite Unit remplace par un stockage en mémoire.
 */
class LoginThrottleManager extends AbstractManager
{
    public const FENETRE_SECONDES = 86400;     // 24 h
    public const BLOCAGE_INITIAL = 60;         // 1 min
    public const BLOCAGE_MAX = 3600;           // 1 h
    private const PURGE_APRES = 2 * 86400;     // lignes > 48 h supprimées

    /**
     * Nombre de secondes pendant lesquelles la clé reste bloquée (0 = libre).
     */
    public function secondesRestantes(string $cle): int
    {
        $ligne = $this->lire($this->hacher($cle));
        if ($ligne === null || $ligne['bloque_jusqu_a'] === null) {
            return 0;
        }
        return max(0, $ligne['bloque_jusqu_a'] - $this->maintenant());
    }

    /**
     * Enregistre un échec (ou une demande, pour le mot de passe oublié).
     *
     * @return int Durée de blocage appliquée en secondes (0 si sous le seuil)
     */
    public function enregistrerEchec(string $cle, int $seuil): int
    {
        $maintenant = $this->maintenant();
        $hache = $this->hacher($cle);
        $ligne = $this->lire($hache);

        $echecs = 0;
        if ($ligne !== null && $maintenant - $ligne['dernier_echec'] <= self::FENETRE_SECONDES) {
            $echecs = $ligne['echecs'];
        }
        $echecs++;

        $blocage = 0;
        if ($echecs >= $seuil) {
            $palier = min($echecs - $seuil, 10); // 2^10 min > plafond : évite tout débordement
            $blocage = min(self::BLOCAGE_INITIAL * (2 ** $palier), self::BLOCAGE_MAX);
        }

        $this->ecrire($hache, $echecs, $maintenant, $blocage > 0 ? $maintenant + $blocage : null);

        // Purge opportuniste (≈ 1 appel sur 50) : pas de tâche planifiée à maintenir.
        if (random_int(1, 50) === 1) {
            $this->purger($maintenant - self::PURGE_APRES);
        }

        return $blocage;
    }

    public function reinitialiser(string $cle): void
    {
        $this->supprimer($this->hacher($cle));
    }

    // ==================================================================
    // Stockage (MySQL) — surchargé en test Unit
    // ==================================================================

    /**
     * @return array{echecs:int,dernier_echec:int,bloque_jusqu_a:?int}|null
     */
    protected function lire(string $cleHachee): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT echecs, dernier_echec, bloque_jusqu_a FROM login_throttle WHERE cle = :cle'
        );
        $stmt->execute(['cle' => $cleHachee]);
        $ligne = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ligne) {
            return null;
        }
        return [
            'echecs'         => (int) $ligne['echecs'],
            'dernier_echec'  => (int) $ligne['dernier_echec'],
            'bloque_jusqu_a' => $ligne['bloque_jusqu_a'] === null ? null : (int) $ligne['bloque_jusqu_a'],
        ];
    }

    protected function ecrire(string $cleHachee, int $echecs, int $dernierEchec, ?int $bloqueJusquA): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO login_throttle (cle, echecs, dernier_echec, bloque_jusqu_a)
             VALUES (:cle, :echecs, :dernier, :bloque)
             ON DUPLICATE KEY UPDATE echecs = VALUES(echecs),
                 dernier_echec = VALUES(dernier_echec), bloque_jusqu_a = VALUES(bloque_jusqu_a)'
        );
        $stmt->bindValue('cle', $cleHachee);
        $stmt->bindValue('echecs', $echecs, \PDO::PARAM_INT);
        $stmt->bindValue('dernier', $dernierEchec, \PDO::PARAM_INT);
        $stmt->bindValue('bloque', $bloqueJusquA, $bloqueJusquA === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->execute();
    }

    protected function supprimer(string $cleHachee): void
    {
        $this->db->prepare('DELETE FROM login_throttle WHERE cle = :cle')->execute(['cle' => $cleHachee]);
    }

    protected function purger(int $avant): void
    {
        $stmt = $this->db->prepare('DELETE FROM login_throttle WHERE dernier_echec < :avant');
        $stmt->bindValue('avant', $avant, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Horloge — surchargée en test pour simuler l'écoulement du temps. */
    protected function maintenant(): int
    {
        return time();
    }

    private function hacher(string $cle): string
    {
        return hash('sha256', $cle);
    }
}
