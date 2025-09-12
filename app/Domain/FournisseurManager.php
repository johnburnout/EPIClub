<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class FournisseurManager extends AbstractManager
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

        $sql = "SELECT * FROM fournisseur $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);

        if ($fournisseur = $stmt->fetch()) {
            return $fournisseur;
        }

        return null;
    }

    public function save(array $fournisseur)
    {
        if (isset($fournisseur['id'])) {
            return $this->_update($fournisseur);
        }

        $this->_insert($fournisseur);
        return $this->db->lastInsertId('fournisseur');
    }

    public function delete(int $id)
    {
        $sql = "DELETE fournisseur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _insert(array $fournisseur)
    {
        $sql = "INSERT INTO fournisseur (nom, email, phone) VALUES (:nom, :email, :phone)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($fournisseur);
    }

    private function _update(array $fournisseur)
    {
        $sql = "UPDATE fournisseur SET nom=:nom, email=:email, phone=:phone WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($fournisseur);
    }
}
