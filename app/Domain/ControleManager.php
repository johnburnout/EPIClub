<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class ControleManager extends AbstractManager
{
    public function findAll($order = 'date_debut DESC')
    {
        $sql = "SELECT c.*, u.prenom, u.nom 
                FROM controle c
                LEFT JOIN utilisateur u ON c.controleur_id = u.id
                ORDER BY $order";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM controle WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function save(array $controle)
    {
        if (isset($controle['id'])) {
            return $this->_update($controle);
        }
        $this->_insert($controle);
        return $this->db->lastInsertId('controle');
    }

    private function _insert(array $controle)
    {
        // S'assurer que les clés existent
        if (!isset($controle['hash_remarques'])) {
            $controle['hash_remarques'] = null;
        }
        
        $sql = "INSERT INTO controle (libelle, date_debut, date_fin, statut, controleur_id, cree_par, hash_remarques)
                VALUES (:libelle, :date_debut, :date_fin, :statut, :controleur_id, :cree_par, :hash_remarques)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($controle);
    }

    private function _update(array $controle)
    {
        // Filtrer les champs pour éviter les erreurs
        $allowedFields = ['libelle', 'date_debut', 'date_fin', 'statut', 'controleur_id', 'hash_remarques', 'id'];
        $filteredControle = array_intersect_key($controle, array_flip($allowedFields));
        
        $sql = "UPDATE controle SET libelle=:libelle, date_debut=:date_debut, date_fin=:date_fin,
                statut=:statut, controleur_id=:controleur_id, hash_remarques=:hash_remarques
                WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filteredControle);
    }
}