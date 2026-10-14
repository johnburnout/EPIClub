<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;
use Epiclub\Exception\DuplicateReferenceException;

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
        
        // [REFACTOR #72] Early return : évite la requête IN vide.
        if ($lignes === []) {
            return $lignes;
        }
        
        // [REFACTOR #72] Collecte des IDs distincts non-null.
        // categorie_id est NOT NULL en base, donc isset() est toujours
        // vrai en pratique — on le garde par défense si le schéma évolue.
        $categorieIds = [];
        foreach ($lignes as $ligne) {
            if (isset($ligne['categorie_id'])) {
                $categorieIds[$ligne['categorie_id']] = true;
            }
        }
        
        $categories = $categorieIds === []
        ? []
        : (new CategorieManager())->findIds(array_keys($categorieIds));
        
        // [REFACTOR #72] ?? null : FK orpheline = clé présente + valeur
        // null (comportement historique de findId() qui retournait null).
        foreach ($lignes as $i => $ligne) {
            if (isset($ligne['categorie_id'])) {
                $lignes[$i]['categorie'] = $categories[$ligne['categorie_id']] ?? null;
            }
        }
        
        return $lignes;
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM acquisition_ligne WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        if (!$ligne = $stmt->fetch()) {
            return null;
        }
        
        // [REFACTOR #79] Alignement sur findByAcquisition() (#72) :
        // une seule requête pour la catégorie via findIds([$id]).
        // Avant : new CategorieManager() + findId() → 2 requêtes.
        $categories = (new CategorieManager())->findIds([(int) $ligne['categorie_id']]);
        
        // [REFACTOR #79] ?? null : FK orpheline = clé présente + valeur
        // null (comportement historique de findId() qui retournait null).
        $ligne['categorie'] = $categories[$ligne['categorie_id']] ?? null;
        
        return $ligne;
    }

    /**
     * Vérifie si une référence existe déjà dans la table acquisition_ligne
     *
     * @param string   $reference La référence à vérifier
     * @param int|null $excludeId ID à exclure (pour les modifications)
     * @return mixed Le résultat de la requête ou null si non trouvé
     *
     * @uses AcquisitionController::update() pour vérifier l'unicité des références
     * @uses AcquisitionLineController::modifyLine() avec $excludeId
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

    /**
     * @throws DuplicateReferenceException si la référence est déjà utilisée
     */
    public function save(array $ligne)
    {
        if (isset($ligne['id'])) {
            return $this->_patch($ligne);
        }

        $this->_create($ligne);
        return $this->db->lastInsertId('acquisition_ligne');
    }

    public function delete(int $id)
    {
        // [REFACTOR #72] Factory protected au lieu de new en dur.
        // Permet de mocker AcquisitionManager en test Unit.
        $ligne = $this->findId($id);
        if ($ligne) {
            $acquisitionManager = $this->acquisitionManager();
            $acquisition = $acquisitionManager->findId($ligne['acquisition_id']);
            if ($acquisition && $acquisition['est_validee'] == 1) {
                return false; // ou lancer une exception
            }
        }
        
        $sql = "DELETE FROM acquisition_ligne WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    /**
     * @throws DuplicateReferenceException
     */
    private function _create(array $ligne)
    {
        $sql = "INSERT INTO acquisition_ligne (acquisition_id, reference, designation, categorie_id, nombre, equipements_generes, regrouper_en_lot)
                VALUES (:acquisition_id, :reference, :designation, :categorie_id, :nombre, :equipements_generes, :regrouper_en_lot)";
        $stmt = $this->db->prepare($sql);
        try {
            return $stmt->execute([
                'acquisition_id'     => $ligne['acquisition_id'],
                'reference'          => $ligne['reference'],
                'designation'        => $ligne['designation'],
                'categorie_id'       => $ligne['categorie_id'],
                'nombre'             => $ligne['nombre'],
                'equipements_generes' => 0,
                'regrouper_en_lot'   => isset($ligne['regrouper_en_lot']) ? $ligne['regrouper_en_lot'] : 0,
            ]);
        } catch (\PDOException $e) {
            // [ROBUSTESSE] La contrainte UNIQUE sur `reference` protège
            // contre la race condition entre findByReference() et l'INSERT.
            if ($this->isDuplicateKey($e)) {
                throw new DuplicateReferenceException(
                    sprintf("La référence '%s' existe déjà.", $ligne['reference']),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * @throws DuplicateReferenceException
     */
    private function _patch(array $ligne)
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
        try {
            return $stmt->execute($filteredLigne);
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new DuplicateReferenceException(
                    sprintf("La référence '%s' est déjà utilisée par une autre ligne.", $ligne['reference'] ?? ''),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Détecte une violation de contrainte UNIQUE MySQL (erreur 1062).
     */
    private function isDuplicateKey(\PDOException $e): bool
    {
        return $e->getCode() === '23000'
            && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
    
    /**
    * [REFACTOR #72] Factory AcquisitionManager — mockable en test Unit.
    *
    * Les sous-classes de test peuvent surcharger cette factory pour
    * injecter un AcquisitionManager mocké (pattern Vague 11).
    */
    protected function acquisitionManager(): AcquisitionManager
    {
        return new AcquisitionManager();
    }
}