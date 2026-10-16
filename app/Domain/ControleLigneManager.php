<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class ControleLigneManager extends AbstractManager
{
    /**
     * [REFACTOR #91] Champs autorisés en écriture.
     * controle_id / equipement_id : insertion seule.
     */
    private const CREATE_FIELDS = ['controle_id', 'equipement_id', 'remarque', 'date_controle', 'statut'];
    private const PATCH_FIELDS = ['remarque', 'date_controle', 'statut'];

    public function findByControle(int $controle_id)
    {
        $sql = "SELECT cl.*, ce.reference, ce.libelle 
                FROM controle_ligne cl
                JOIN club_equipement ce ON cl.equipement_id = ce.id
                WHERE cl.controle_id = :controle_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['controle_id' => $controle_id]);
        return $stmt->fetchAll();
    }

    /**
     * Récupère les lignes de contrôle pour un équipement donné
     */
    public function findByEquipement(int $equipement_id)
    {
        $sql = "SELECT cl.*, c.statut as controle_statut, c.controleur_id
                FROM controle_ligne cl
                JOIN controle c ON cl.controle_id = c.id
                WHERE cl.equipement_id = :equipement_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['equipement_id' => $equipement_id]);
        return $stmt->fetchAll();
    }

    /**
     * Récupère une ligne de contrôle par controle_id et equipement_id
     */
    public function findByControleAndEquipement(int $controleId, int $equipementId): ?array
    {
        $sql = "SELECT * FROM controle_ligne WHERE controle_id = :controle_id AND equipement_id = :equipement_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'controle_id' => $controleId,
            'equipement_id' => $equipementId
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Ajoute un équipement à un contrôle
     */
    public function addEquipement(int $controleId, int $equipementId): bool
    {
        // Vérifier si l'équipement est déjà dans le contrôle
        $sql = "SELECT COUNT(*) FROM controle_ligne WHERE controle_id = :controle_id AND equipement_id = :equipement_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'controle_id' => $controleId,
            'equipement_id' => $equipementId
        ]);
        
        if ($stmt->fetchColumn() > 0) {
            return true;
        }
        
        // Ajouter l'équipement
        $sql = "INSERT INTO controle_ligne (controle_id, equipement_id, statut) VALUES (:controle_id, :equipement_id, 'a_controler')";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'controle_id' => $controleId,
            'equipement_id' => $equipementId
        ]);
    }

    /**
    * [REFACTOR #89] Retourne `null` quand la ligne est absente
    * (uniformisation du contrat findId, divergence #88 corrigée).
    * Tous les appelants testent `!$ligne` (falsy) : iso-comportement.
    */
    public function findId(int $id)
    {
        $sql = "SELECT * FROM controle_ligne WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function save(array $ligne)
    {
        if (isset($ligne['id'])) {
            return $this->_patch($ligne);
        }
        $this->_create($ligne);
        return $this->db->lastInsertId('controle_ligne');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM controle_ligne WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _create(array $ligne)
    {
        return $this->filteredInsert($ligne, self::CREATE_FIELDS);
    }

    private function _patch(array $ligne)
    {
        // controle_id / equipement_id ne sont pas modifiables après création
        return $this->filteredUpdate($ligne, self::PATCH_FIELDS);
    }

    /**
     * [REFACTOR #91] Table pour filteredInsert()/filteredUpdate()
     * (AbstractManager).
     */
    protected function tableName(): string
    {
        return 'controle_ligne';
    }
}