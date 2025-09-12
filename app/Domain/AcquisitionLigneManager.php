<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class AcquisitionLigneManager extends AbstractManager
{
    public function findByAcquisition(int $acquisition_id)
    {
        $sql = "SELECT * FROM acquisition_ligne WHERE acquisition_id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $acquisition_id
        ]);

        $lignes = $stmt->fetchAll();

        $categorieManager = new CategorieManager();

        foreach ($lignes as $i => $ligne) {
            $lignes[$i]['categorie'] = $categorieManager->findId($ligne['categorie_id']);
        }

        return $lignes;
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM acquisition_ligne WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        if ($ligne = $stmt->fetch()) {
            $categorieManager = new CategorieManager();
            $ligne['categorie'] = $categorieManager->findId($ligne['categorie_id']);
            return $ligne;
        }

        return null;
    }

    public function save(array $ligne)
    {
        if (isset($ligne['id'])) {
            return $this->_update($ligne);
        }

        $this->_insert($ligne);
        return $this->db->lastInsertId('acquisition_ligne');
    }

    public function delete(int $id)
    {
        $sql = "DELETE acquisition_ligne WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _insert(array $ligne)
    {
        $sql = "INSERT INTO acquisition_ligne (acquisition_id, reference, designation, categorie_id, nombre, equipements_generes) 
            VALUES (:acquisition_id, :reference, :designation, :categorie_id, :nombre, :equipements_generes)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'acquisition_id' => $ligne['acquisition_id'],
            'reference' => $ligne['reference'],
            'designation' => $ligne['designation'],
            'categorie_id' => $ligne['categorie_id'],
            'nombre' => $ligne['nombre'],
            'equipements_generes' => 0
        ]);
    }

    private function _update(array $acquisition)
    {
        if ($acquisition['equipements_generes'] === 1) {
            return false;
        }

        $sql = "UPDATE acquisition_ligne 
            SET acquisition_id=:acquisition_id, reference=:reference, designation=:designation, categorie_id=:categorie_id, nombre=:nombre, equipements_generes=:equipements_generes
            WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($acquisition);
    }
}
