<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class UtilisateurManager extends AbstractManager
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

        $sql = "SELECT * FROM utilisateur $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM utilisateur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        if ($utilisateur = $stmt->fetch()) {
            return $utilisateur;
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

        $sql = "SELECT * FROM utilisateur $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);

        if ($utilisateur = $stmt->fetch()) {
            return $utilisateur;
        }

        return null;
    }

    public function save(array $utilisateur)
    {
        if (isset($utilisateur['id'])) {
            return $this->_update($utilisateur);
        }

        $this->_insert($utilisateur);
        return $this->db->lastInsertId('utilisateur');
    }

    public function delete(int $id)
    {
        $sql = "DELETE utilisateur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _insert(array $utilisateur)
    {
        $sql = "INSERT INTO utilisateur (nom, prenom, username, email, password, role, date_creation, derniere_connexion) 
            VALUES (:nom, :prenom, :username, :email, :password, :role, :date_creation, :derniere_connexion)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($utilisateur);
    }

    private function _update(array $utilisateur)
    {
        $sql = "UPDATE utilisateur 
            SET nom=:nom, prenom=:prenom, username=:username, email=:email, password=:password, role=:role, date_creation=:date_creation, 
            derniere_connexion=:derniere_connexion
            WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($utilisateur);
    }
}
