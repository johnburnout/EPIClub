<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class FournisseurManager extends AbstractManager
{
    /** [REFACTOR #91] Champs autorisés en écriture (filteredInsert / filteredUpdate). */
    private const ALLOWED_FIELDS = ['nom', 'email', 'phone'];

    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
                . $this->buildLimitClause($limit, $offset);

        $sql = "SELECT * FROM fournisseur $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM fournisseur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        if ($fournisseur = $stmt->fetch()) {
            return $fournisseur;
        }
        return null;
    }
    
    /**
    * [REFACTOR #71] Récupère plusieurs fournisseurs par leurs IDs en une requête.
    *
    * Iso-comportement avec findId() appliqué en boucle :
    *  - les IDs absents de la table sont absents du tableau retourné
    *    (findId() retournait null, on omet la clé — l'appelant utilise ?? null) ;
    *  - les doublons dans $ids sont dédupliqués (IN (...) n'a pas besoin
    *    de répétition) ;
    *  - $ids vide → retourne [] sans requête SQL.
    *
    * @param int[] $ids
    * @return array<int, array> Tableau indexé par id.
    */
    public function findIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        
        $ids = array_values(array_unique(array_map('intval', $ids)));
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM fournisseur WHERE id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        
        return array_column($stmt->fetchAll(), null, 'id');
    }

    /**
    * [REFACTOR #87] Table pour findOneByCriteria() générique
    * (AbstractManager).
    */
    protected function tableName(): string
    {
        return 'fournisseur';
    }

    public function save(array $fournisseur)
    {
        if (isset($fournisseur['id'])) {
            return $this->_patch($fournisseur);
        }

        $this->_create($fournisseur);
        return $this->db->lastInsertId('fournisseur');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM fournisseur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function hasAcquisitions(int $id): bool
    {
        $sql = "SELECT COUNT(*) FROM acquisition WHERE fournisseur_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() > 0;
    }

    private function _create(array $fournisseur)
    {
        // email / phone : défaut null historique
        return $this->filteredInsert($fournisseur, self::ALLOWED_FIELDS, ['email' => null, 'phone' => null]);
    }

    private function _patch(array $fournisseur)
    {
        return $this->filteredUpdate($fournisseur, self::ALLOWED_FIELDS, ['email' => null, 'phone' => null]);
    }
}