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
        if ($limit > 0) {
            $params .= " LIMIT $offset, $limit"; // note : l'ordre des paramètres peut varier
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
        return $stmt->fetch() ?: null;
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
        return $stmt->fetch() ?: null;
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
        $sql = "DELETE FROM utilisateur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _insert(array $utilisateur)
    {
        // Définir les valeurs par défaut pour les champs optionnels
        $utilisateur['controle_en_cours_id'] = $utilisateur['controle_en_cours_id'] ?? null;
        $utilisateur['last_activity'] = $utilisateur['last_activity'] ?? null;
        
        // Ne garder que les champs autorisés
        $allowedFields = ['nom', 'prenom', 'username', 'email', 'password', 'role', 'date_creation', 'derniere_connexion', 'controle_en_cours_id', 'last_activity'];
        $filtered = array_intersect_key($utilisateur, array_flip($allowedFields));
        
        $sql = "INSERT INTO utilisateur (nom, prenom, username, email, password, role, date_creation, derniere_connexion, controle_en_cours_id, last_activity) 
        VALUES (:nom, :prenom, :username, :email, :password, :role, :date_creation, :derniere_connexion, :controle_en_cours_id, :last_activity)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filtered);
    }

    private function _update(array $utilisateur)
    {
        // Filtrer les champs pour ne garder que ceux existants dans la table
        $allowedFields = ['nom', 'prenom', 'username', 'email', 'password', 'role', 'date_creation', 'derniere_connexion', 'controle_en_cours_id', 'last_activity', 'id'];
        $filtered = array_intersect_key($utilisateur, array_flip($allowedFields));
        
        $sql = "UPDATE utilisateur 
                SET nom=:nom, prenom=:prenom, username=:username, email=:email, password=:password, role=:role, 
                    date_creation=:date_creation, derniere_connexion=:derniere_connexion, 
                    controle_en_cours_id=:controle_en_cours_id, last_activity=:last_activity
                WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filtered);
    }
}