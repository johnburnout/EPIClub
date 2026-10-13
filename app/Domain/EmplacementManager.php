<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class EmplacementManager extends AbstractManager
{
    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
                . $this->buildLimitClause($limit, $offset);

        $sql = "SELECT * FROM emplacement $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM emplacement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        if ($emplacement = $stmt->fetch()) {
            return $emplacement;
        }

        return null;
    }

    public function findOneByCriteria(array $criteria = [])
    {
        [$where, $params] = $this->buildWhereClause($criteria);
        
        $sql = "SELECT * FROM emplacement$where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        if ($emplacement = $stmt->fetch()) {
            return $emplacement;
        }
        
        return null;
    }

    public function save(array $emplacement)
    {
        if (isset($emplacement['id'])) {
            return $this->_patch($emplacement);
        }

        $this->_create($emplacement);
        return $this->db->lastInsertId('emplacement');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM emplacement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function hasEquipements(int $id): bool
    {
        $sql = "SELECT COUNT(*) FROM club_equipement WHERE emplacement_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() > 0;
    }

    private function _create(array $emplacement)
    {
        $sql = "INSERT INTO emplacement (libelle, description, image) VALUES (:libelle, :description, :image)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($emplacement);
    }

    private function _patch(array $emplacement)
    {
        $sql = "UPDATE emplacement SET libelle=:libelle, description=:description, image=:image WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($emplacement);
    }
}