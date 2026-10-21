<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

/**
 * Fiches de prêt de matériel (table `pret`, migration 016).
 */
class PretManager extends AbstractManager
{
    private const CREATE_FIELDS = [
        'emprunteur_nom', 'emprunteur_prenom', 'emprunteur_telephone', 'emprunteur_licence',
        'date_pret', 'date_retour_prevue', 'statut', 'remarques', 'cree_par',
    ];

    private const PATCH_FIELDS = [
        'emprunteur_nom', 'emprunteur_prenom', 'emprunteur_telephone', 'emprunteur_licence',
        'date_pret', 'date_retour_prevue', 'date_retour', 'statut', 'remarques',
        'signature', 'date_signature', 'valide_par', 'termine_par', 'date_validation', 'date_fin',
    ];

    /** Colonnes de la liste (sans la signature, potentiellement lourde). */
    private const COLONNES_LISTE = 'p.id, p.emprunteur_nom, p.emprunteur_prenom, p.emprunteur_telephone,
        p.emprunteur_licence, p.date_pret, p.date_retour_prevue, p.date_retour, p.statut, p.created_at,
        p.date_validation, p.date_fin, (p.signature IS NOT NULL) AS signe';

    protected function tableName(): string
    {
        return 'pret';
    }

    /**
     * Fiches de prêt, les plus récentes d'abord, avec le nombre de
     * matériels et le nombre de matériels restant à vérifier.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllAvecCompteurs(?string $statut = null): array
    {
        $where = '';
        $params = [];
        if ($statut !== null) {
            $where = 'WHERE p.statut = :statut';
            $params['statut'] = $statut;
        }
        $sql = 'SELECT ' . self::COLONNES_LISTE . ',
                       COUNT(pl.id) AS nb_materiels,
                       COALESCE(SUM(pl.id IS NOT NULL AND pl.etat_retour IS NULL), 0) AS nb_a_verifier
                  FROM pret p
             LEFT JOIN pret_ligne pl ON pl.pret_id = p.id
                 ' . $where . '
              GROUP BY p.id
              ORDER BY FIELD(p.statut, \'saisie\', \'en_cours\', \'termine\'), p.date_pret DESC, p.id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> Prêts en saisie (ajout de matériel possible). */
    public function findEnSaisie(): array
    {
        $stmt = $this->db->query(
            'SELECT ' . self::COLONNES_LISTE . " FROM pret p WHERE p.statut = 'saisie' ORDER BY p.id DESC"
        );
        return $stmt->fetchAll();
    }

    /** Nombre de prêts en cours dont la date de retour prévue est dépassée. */
    public function countEnRetard(string $aujourdhui): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pret WHERE statut = 'en_cours' AND date_retour_prevue < :jour"
        );
        $stmt->execute(['jour' => $aujourdhui]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findId(int $id): ?array
    {
        $sql = 'SELECT p.*,
                       uc.prenom AS cree_par_prenom, uc.nom AS cree_par_nom,
                       uv.prenom AS valide_par_prenom, uv.nom AS valide_par_nom,
                       ut.prenom AS termine_par_prenom, ut.nom AS termine_par_nom
                  FROM pret p
             LEFT JOIN utilisateur uc ON uc.id = p.cree_par
             LEFT JOIN utilisateur uv ON uv.id = p.valide_par
             LEFT JOIN utilisateur ut ON ut.id = p.termine_par
                 WHERE p.id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Crée (sans id) ou met à jour (avec id) une fiche.
     *
     * @param array<string, mixed> $pret
     * @return int|bool Id créé, ou résultat de la mise à jour
     */
    public function save(array $pret): int|bool
    {
        if (isset($pret['id'])) {
            return $this->filteredUpdate($pret, self::PATCH_FIELDS);
        }
        $this->filteredInsert($pret, self::CREATE_FIELDS, ['statut' => 'saisie']);
        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM pret WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /** Transactions : la validation et le retour modifient plusieurs tables. */
    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollBack(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /**
     * Met à jour le statut d'équipements (même connexion que la
     * transaction en cours).
     *
     * @param int[] $equipementIds
     */
    public function changerStatutEquipements(array $equipementIds, int $statut): void
    {
        $ids = array_values(array_unique(array_map('intval', $equipementIds)));
        if ($ids === []) {
            return;
        }
        $marqueurs = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("UPDATE club_equipement SET statut = ? WHERE id IN ($marqueurs)");
        $stmt->execute(array_merge([$statut], $ids));
    }

    /**
     * Verrouille et relit les équipements (transaction de validation) :
     * l'éligibilité est revérifiée sur l'état le plus récent.
     *
     * @param int[] $equipementIds
     * @return array<int, array<string, mixed>> Map id => équipement
     */
    public function equipementsPourMiseAJour(array $equipementIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $equipementIds)));
        if ($ids === []) {
            return [];
        }
        $marqueurs = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM club_equipement WHERE id IN ($marqueurs) FOR UPDATE");
        $stmt->execute($ids);
        return array_column($stmt->fetchAll(), null, 'id');
    }

    /**
     * Fige les lignes vérifiées au retour (même connexion que la
     * transaction en cours).
     */
    public function enregistrerRetourLigne(int $ligneId, string $etat, ?int $utilisateurId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pret_ligne SET etat_retour = :etat, date_verification = NOW(), verifie_par = :par
              WHERE id = :id AND etat_retour IS NULL'
        );
        $stmt->execute(['etat' => $etat, 'par' => $utilisateurId, 'id' => $ligneId]);
    }
}
