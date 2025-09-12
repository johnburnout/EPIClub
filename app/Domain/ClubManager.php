<?php 
namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class ClubManager extends AbstractManager
{
    public function findParameters()
    {
        $sql = "SELECT * FROM club WHERE id=1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        if ($parameters = $stmt->fetch()) {
            return $parameters;
        }

        return null;
    }

    public function save(array $club)
    {
        if (isset($club['id'])) {
            return $this->_update($club);
        }

        $this->_insert($club);
        return $this->db->lastInsertId('club');
    }

    private function _insert(array $club)
    {
        $sql = "INSERT INTO club (nom, activite, description, email, phone) 
            VALUES (:nom, :activite, :description, :email, :phone)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($club);
    }

    private function _update(array $club)
    {
        $sql = "UPDATE club 
            SET nom=:nom, activite=:activite, description=:description, email=:email, phone=:phone
            WHERE id=1";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($club);
    }
}