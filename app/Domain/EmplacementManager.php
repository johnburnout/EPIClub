<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class EmplacementManager extends AbstractManager
{
    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = '';

        if ($order) {
            $params .= " ORDER BY $order";
        }

        if ($limit > 1) {
            $params .= " LIMIT $limit, $offset";
        }

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
        $params = '';
        $i = 0;
        foreach ($criteria as $key => $value) {
            if ($i === 0) {
                $params .= "WHERE $key=:$key";
            } else {
                $params .= " AND $key=:$key";
            }
            $i++;
        }

        $sql = "SELECT * FROM emplacement $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);

        if ($emplacement = $stmt->fetch()) {
            return $emplacement;
        }

        return null;
    }

    public function save(array $emplacement)
    {
        if (isset($emplacement['id'])) {
            return $this->_update($emplacement);
        }

        $this->_insert($emplacement);
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

    private function _insert(array $emplacement)
    {
        $sql = "INSERT INTO emplacement (libelle, description, image) VALUES (:libelle, :description, :image)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($emplacement);
    }

    private function _update(array $emplacement)
    {
        $sql = "UPDATE emplacement SET libelle=:libelle, description=:description, image=:image WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($emplacement);
    }
}