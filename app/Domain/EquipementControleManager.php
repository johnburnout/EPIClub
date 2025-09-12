<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class EquipementControleManager extends AbstractManager
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

        $sql = "SELECT * FROM club_equipement_controle $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM club_equipement_controle WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        if ($controle = $stmt->fetch()) {
            return $controle;
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

        $sql = "SELECT * FROM club_equipement_controle $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);

        if ($controle = $stmt->fetch()) {
            return $controle;
        }

        return null;
    }

    public function save(array $controle)
    {
        if (isset($controle['id'])) {
            return $this->_update($controle);
        }

        $this->_insert($controle);

        return $this->db->lastInsertId('club_equipement_controle');
    }

    /** @deprecated Ne jamais supprimer un controle, en faire un nouveau qui remplace/corrige l'actuel */
    public function delete(int $id)
    {
        /* $sql = "DELETE club_equipement_controle WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]); */

        throw new \Exception("La suppression d'un controle est impossible.", 1);
    }

    private function _insert(array $controle)
    {
        $sql = "INSERT INTO club_equipement_controle (controleur_id, club_equipement_id, etat, remarques, date_controle)
            VALUES (:controleur_id, :club_equipement_id, :etat, :remarques, :date_controle)";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($controle);
    }

    private function _update(array $controle)
    {
        $sql = "UPDATE club_equipement_controle 
            SET controleur_id=:controleur_id, club_equipement_id=:club_equipement_id, etat=:etat, remarques=:remarques, date_controle=:date_controle
            WHERE id=:id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($controle);
    }
}
