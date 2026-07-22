<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class EquipementManager extends AbstractManager
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

        $sql = "SELECT * FROM club_equipement $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM club_equipement WHERE id=:id";
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

        $sql = "SELECT * FROM club_equipement $params";
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
        return $this->db->lastInsertId('club_equipement');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM club_equipement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function codeExists(string $code): bool
    {
        $sql = "SELECT COUNT(*) FROM club_equipement WHERE code = :code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['code' => $code]);
        return $stmt->fetchColumn() > 0;
    }

    private function _insert(array $equipement)
    {
        $allowedFields = [
            'acquisition_id', 'categorie_id', 'reference', 'libelle', 'code', 
            'statut', 'remarques', 'date_dernier_controle', 'controle_en_cours', 
            'emplacement_id', 'date_mise_en_service', 'date_fin_utilisation',
            'nombre',
            'photo'  // Ajout pour la gestion de la photo
        ];
        $filteredEquipement = array_intersect_key($equipement, array_flip($allowedFields));
        
        $sql = "INSERT INTO club_equipement 
        (acquisition_id, categorie_id, reference, libelle, code, statut, remarques, date_dernier_controle, controle_en_cours, emplacement_id, date_mise_en_service, date_fin_utilisation, nombre, photo)
        VALUES 
        (:acquisition_id, :categorie_id, :reference, :libelle, :code, :statut, :remarques, :date_dernier_controle, :controle_en_cours, :emplacement_id, :date_mise_en_service, :date_fin_utilisation, :nombre, :photo)";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($filteredEquipement);
        if (!$result) {
            var_dump('❌ Erreur SQL :', $stmt->errorInfo());
        }
        return $result;
    }
    
    private function _update(array $equipement)
    {
        $allowedFields = [
            'acquisition_id', 'categorie_id', 'reference', 'libelle', 'code', 
            'statut', 'remarques', 'date_dernier_controle', 'controle_en_cours', 
            'emplacement_id', 'id', 'date_mise_en_service', 'date_fin_utilisation',
            'nombre',
            'photo'  // Ajout pour la gestion de la photo
        ];
        $filteredEquipement = array_intersect_key($equipement, array_flip($allowedFields));
        
        $sql = "UPDATE club_equipement 
        SET acquisition_id=:acquisition_id, categorie_id=:categorie_id, reference=:reference, libelle=:libelle, code=:code, 
        statut=:statut, remarques=:remarques, date_dernier_controle=:date_dernier_controle, controle_en_cours=:controle_en_cours,
        emplacement_id=:emplacement_id,
        date_mise_en_service=:date_mise_en_service,
        date_fin_utilisation=:date_fin_utilisation,
        nombre=:nombre,
        photo=:photo
        WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filteredEquipement);
    }
}