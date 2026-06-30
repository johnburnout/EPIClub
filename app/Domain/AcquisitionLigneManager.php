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

    /**
    * Vérifie si une référence existe déjà dans la table acquisition_ligne
    * 
    * @param string $reference La référence à vérifier
    * @param int|null $excludeId ID à exclure (pour les modifications)
    * @return mixed Le résultat de la requête ou null si non trouvé
    * 
    * @uses AcquisitionController::update() pour vérifier l'unicité des références
    */
    public function findByReference(string $reference, ?int $excludeId = null)
    {
        $sql = "SELECT * FROM acquisition_ligne WHERE reference = :reference";
        $params = ['reference' => $reference];
        
        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params['id'] = $excludeId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
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
        // Vérifier si la ligne appartient à une acquisition validée
        $ligne = $this->findId($id);
        if ($ligne) {
            $acquisitionManager = new AcquisitionManager();
            $acquisition = $acquisitionManager->findId($ligne['acquisition_id']);
            if ($acquisition && $acquisition['est_validee'] == 1) {
                return false; // ou lancer une exception
            }
        }
        
        $sql = "DELETE FROM acquisition_ligne WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _insert(array $ligne)
    {
        $sql = "INSERT INTO acquisition_ligne (acquisition_id, reference, designation, categorie_id, nombre, equipements_generes, regrouper_en_lot) 
                VALUES (:acquisition_id, :reference, :designation, :categorie_id, :nombre, :equipements_generes, :regrouper_en_lot)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'acquisition_id' => $ligne['acquisition_id'],
            'reference' => $ligne['reference'],
            'designation' => $ligne['designation'],
            'categorie_id' => $ligne['categorie_id'],
            'nombre' => $ligne['nombre'],
            'equipements_generes' => 0,
            'regrouper_en_lot' => isset($ligne['regrouper_en_lot']) ? $ligne['regrouper_en_lot'] : 0
        ]);
    }
    
    private function _update(array $ligne)
    {
        // Filtrer les champs pour éviter les erreurs
        $allowedFields = ['acquisition_id', 'reference', 'designation', 'categorie_id', 'nombre', 'equipements_generes', 'regrouper_en_lot', 'id'];
        $filteredLigne = array_intersect_key($ligne, array_flip($allowedFields));
        
        $sql = "UPDATE acquisition_ligne 
                SET acquisition_id=:acquisition_id, reference=:reference, designation=:designation, 
                    categorie_id=:categorie_id, nombre=:nombre, equipements_generes=:equipements_generes,
                    regrouper_en_lot=:regrouper_en_lot
                WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filteredLigne);
    }
}