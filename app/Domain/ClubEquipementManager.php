<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class ClubEquipementManager extends AbstractManager
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

        $sql = "SELECT * FROM equipement $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM equipement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        if ($equipement = $stmt->fetch()) {
            return $equipement;
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

        $sql = "SELECT * FROM equipement $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);

        if ($equipement = $stmt->fetch()) {
            return $equipement;
        }

        return null;
    }

    public function save(array $equipement)
    {
        if (isset($equipement['id'])) {
            return $this->_update($equipement);
        }

        $this->_insert($equipement);
        return $this->db->lastInsertId('equipement');
    }

    public function delete(int $id)
    {
        $sql = "DELETE equipement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _insert(array $equipement)
    {
        $sql = "INSERT INTO equipement (equipement_categorie_id, acquisition_id, reference, libelle, statut, remarques, date_dernier_controle, controle_en_cours)
            VALUES (:equipement_categorie_id, :acquisition_id, :reference, :libelle, :statut, :remarques, :date_dernier_controle, :controle_en_cours)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($equipement);
    }

    private function _update(array $equipement)
    {
        $sql = "UPDATE equipement 
            SET equipement_categorie_id=:equipement_categorie_id, acquisition_id=:acquisition_id, reference=:reference, libelle=:libelle, statut=:statut, remarques=:remarques,
            date_dernier_controle=:date_dernier_controle, controle_en_cours=:controle_en_cours
            WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($equipement);
    }
}
