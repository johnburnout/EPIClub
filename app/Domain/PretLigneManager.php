<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

/**
 * Matériel inscrit sur une fiche de prêt (table `pret_ligne`, migration 016).
 */
class PretLigneManager extends AbstractManager
{
    protected function tableName(): string
    {
        return 'pret_ligne';
    }

    /**
     * Lignes d'un prêt avec les informations de l'équipement.
     *
     * @return list<array<string, mixed>>
     */
    public function findByPret(int $pretId): array
    {
        $sql = 'SELECT pl.*, ce.reference, ce.libelle, ce.photo, ce.statut AS equipement_statut,
                       ce.controle_en_cours, ce.date_fin_utilisation, ce.deleted_at,
                       c.libelle AS categorie_libelle
                  FROM pret_ligne pl
                  JOIN club_equipement ce ON ce.id = pl.equipement_id
             LEFT JOIN categorie c ON c.id = ce.categorie_id
                 WHERE pl.pret_id = :pret
              ORDER BY ce.reference';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['pret' => $pretId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pret_ligne WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Prêt non clos (en saisie, en cours, ou terminé avec ce matériel
     * pas encore vérifié) sur lequel figure l'équipement.
     *
     * @return array<string, mixed>|null
     */
    public function findPretOuvertPourEquipement(int $equipementId, ?int $saufPretId = null): ?array
    {
        $sql = "SELECT p.id, p.statut, p.emprunteur_nom, p.emprunteur_prenom, p.date_retour_prevue
                  FROM pret_ligne pl
                  JOIN pret p ON p.id = pl.pret_id
                 WHERE pl.equipement_id = :eq
                   AND (p.statut IN ('saisie', 'en_cours') OR pl.etat_retour IS NULL)";
        $params = ['eq' => $equipementId];
        if ($saufPretId !== null) {
            $sql .= ' AND p.id <> :sauf';
            $params['sauf'] = $saufPretId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY p.id DESC LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Ids des équipements inscrits sur un prêt non clos (cf.
     * findPretOuvertPourEquipement).
     *
     * @return int[]
     */
    public function equipementsDansUnPretOuvert(): array
    {
        $stmt = $this->db->query(
            "SELECT DISTINCT pl.equipement_id
               FROM pret_ligne pl
               JOIN pret p ON p.id = pl.pret_id
              WHERE p.statut IN ('saisie', 'en_cours') OR pl.etat_retour IS NULL"
        );
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Historique des prêts d'un équipement (les plus récents d'abord).
     *
     * @return list<array<string, mixed>>
     */
    public function historiqueEquipement(int $equipementId): array
    {
        $sql = 'SELECT p.id, p.statut, p.emprunteur_nom, p.emprunteur_prenom, p.date_pret,
                       p.date_retour_prevue, p.date_retour, pl.etat_retour
                  FROM pret_ligne pl
                  JOIN pret p ON p.id = pl.pret_id
                 WHERE pl.equipement_id = :eq
              ORDER BY p.date_pret DESC, p.id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['eq' => $equipementId]);
        return $stmt->fetchAll();
    }

    public function ajouter(int $pretId, int $equipementId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO pret_ligne (pret_id, equipement_id) VALUES (:pret, :eq)'
        );
        $stmt->execute(['pret' => $pretId, 'eq' => $equipementId]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM pret_ligne WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
