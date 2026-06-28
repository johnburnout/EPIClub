<?php 
namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class CategorieManager extends AbstractManager
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

        $sql = "SELECT * FROM categorie $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM categorie WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        if ($categorie = $stmt->fetch()) {
            return $categorie;
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

        $sql = "SELECT * FROM categorie $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);

        if ($categorie = $stmt->fetch()) {
            return $categorie;
        }
        return null;
    }

    public function save(array $categorie)
    {
        if (isset($categorie['id'])) {
            return $this->_update($categorie);
        }
        $this->_insert($categorie);
        return $this->db->lastInsertId('categorie');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM categorie WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function hasEquipements(int $id): bool
    {
        $sql = "SELECT COUNT(*) FROM club_equipement WHERE categorie_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() > 0;
    }

    private function _insert(array $categorie)
    {
        $defaults = [
            'libelle' => '',
            'description' => null,
            'image' => null,
            'est_epi' => 1
        ];
        
        $filteredCategorie = array_intersect_key($categorie, $defaults);
        $filteredCategorie = array_merge($defaults, $filteredCategorie);
        
        $sql = "INSERT INTO categorie (libelle, description, image, est_epi) VALUES (:libelle, :description, :image, :est_epi)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filteredCategorie);
    }
    
    private function _update(array $categorie)
    {
        $defaults = [
            'libelle' => '',
            'description' => null,
            'image' => null,
            'est_epi' => 1,
            'id' => null
        ];
        
        $filteredCategorie = array_intersect_key($categorie, $defaults);
        $filteredCategorie = array_merge($defaults, $filteredCategorie);
        
        $sql = "UPDATE categorie SET libelle=:libelle, description=:description, image=:image, est_epi=:est_epi WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filteredCategorie);
    }
}