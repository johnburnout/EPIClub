<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class FournisseurManager extends AbstractManager
{
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

    public function findOneByCriteria(array $criteria = [])
    {
        [$where, $params] = $this->buildWhereClause($criteria);
        
        $sql = "SELECT * FROM fournisseur$where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        if ($fournisseur = $stmt->fetch()) {
            return $fournisseur;
        }
        return null;
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
        // S'assurer que les clés existent
        if (!isset($fournisseur['email'])) {
            $fournisseur['email'] = null;
        }
        if (!isset($fournisseur['phone'])) {
            $fournisseur['phone'] = null;
        }

        $sql = "INSERT INTO fournisseur (nom, email, phone) VALUES (:nom, :email, :phone)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($fournisseur);
    }

    private function _patch(array $fournisseur)
    {
        // S'assurer que les clés existent
        if (!isset($fournisseur['email'])) {
            $fournisseur['email'] = null;
        }
        if (!isset($fournisseur['phone'])) {
            $fournisseur['phone'] = null;
        }

        $sql = "UPDATE fournisseur SET nom=:nom, email=:email, phone=:phone WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($fournisseur);
    }
}