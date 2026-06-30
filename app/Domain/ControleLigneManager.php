<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class ControleLigneManager extends AbstractManager
{
    public function findByControle(int $controle_id)
    {
        $sql = "SELECT * FROM controle_ligne WHERE controle_id = :controle_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['controle_id' => $controle_id]);
        return $stmt->fetchAll();
    }

    public function save(array $ligne)
    {
        if (isset($ligne['id'])) {
            return $this->_update($ligne);
        }
        $this->_insert($ligne);
        return $this->db->lastInsertId('controle_ligne');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM controle_ligne WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _insert(array $ligne)
    {
        $sql = "INSERT INTO controle_ligne (controle_id, equipement_id, remarque, date_controle, statut)
                VALUES (:controle_id, :equipement_id, :remarque, :date_controle, :statut)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($ligne);
    }

    private function _update(array $ligne)
    {
        $sql = "UPDATE controle_ligne SET remarque=:remarque, date_controle=:date_controle, statut=:statut
                WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($ligne);
    }
}