<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class CategorieManager extends AbstractManager
{
    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
                . $this->buildLimitClause($limit, $offset);

        $sql = "SELECT * FROM categorie $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM categorie WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        if ($categorie = $stmt->fetch()) {
            return $categorie;
        }
        return null;
    }
    
    /**
    * [REFACTOR #72] Récupère plusieurs catégories par leurs IDs en une requête.
    *
    * Iso-comportement avec findId() appliqué en boucle :
    *  - les IDs absents de la table sont absents du tableau retourné
    *    (findId() retournait null, on omet la clé — l'appelant utilise ?? null) ;
    *  - les doublons dans $ids sont dédupliqués ;
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
        $sql = "SELECT * FROM categorie WHERE id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        
        return array_column($stmt->fetchAll(), null, 'id');
    }

    public function findOneByCriteria(array $criteria = [])
    {
        [$where, $params] = $this->buildWhereClause($criteria);
        
        $sql = "SELECT * FROM categorie$where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        if ($categorie = $stmt->fetch()) {
            return $categorie;
        }
        return null;
    }

    public function save(array $categorie)
    {
        if (isset($categorie['id'])) {
            return $this->_patch($categorie);
        }
        $this->_create($categorie);
        return $this->db->lastInsertId('categorie');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM categorie WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function hasEquipements(int $id): bool
    {
        $sql = "SELECT COUNT(*) FROM club_equipement WHERE categorie_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() > 0;
    }

    private function _create(array $categorie)
    {
        $defaults = [
            'libelle' => '',
            'description' => null,
            'image' => null,
            'est_epi' => 1
        ];

        $filteredCategorie = array_intersect_key($categorie, $defaults);
        $filteredCategorie = array_merge($defaults, $filteredCategorie);

        $sql = "INSERT INTO categorie (libelle, description, image, est_epi) VALUES (:libelle, :description, :image, :est_epi)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filteredCategorie);
    }

    private function _patch(array $categorie)
    {
        $defaults = [
            'libelle' => '',
            'description' => null,
            'image' => null,
            'est_epi' => 1,
            'id' => null
        ];

        $filteredCategorie = array_intersect_key($categorie, $defaults);
        $filteredCategorie = array_merge($defaults, $filteredCategorie);

        $sql = "UPDATE categorie SET libelle=:libelle, description=:description, image=:image, est_epi=:est_epi WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filteredCategorie);
    }
}