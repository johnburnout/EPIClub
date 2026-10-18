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
        : $this->categorieManager()->findIds(array_keys($categorieIds));
        
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
        $categories = $this->categorieManager()->findIds([(int) $ligne['categorie_id']]);
        
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
     * [REFACTOR #92] Champs autorisés en écriture (filteredInsert /
     * filteredUpdate).
     */
    private const ALLOWED_FIELDS = ['acquisition_id', 'reference', 'designation', 'categorie_id', 'nombre', 'equipements_generes', 'regrouper_en_lot', 'reference_forcee'];

    /**
     * @throws DuplicateReferenceException
     */
    private function _create(array $ligne)
    {
        // [REFACTOR #92] SQL délégué au helper générique ; les règles
        // métier historiques sont conservées en pré-traitement :
        //  - equipements_generes TOUJOURS forcé à 0 à la création,
        //    même si fourni par l'appelant ;
        //  - regrouper_en_lot : défaut 0 (isset() historique : une
        //    valeur null explicite retombait aussi sur 0).
        $ligne['equipements_generes'] = 0;
        $ligne['regrouper_en_lot'] = $ligne['regrouper_en_lot'] ?? 0;

        try {
            return $this->filteredInsert($ligne, self::ALLOWED_FIELDS);
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
        try {
            return $this->filteredUpdate($ligne, self::ALLOWED_FIELDS);
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
    * [REFACTOR #92] Table pour filteredInsert()/filteredUpdate()
    * (AbstractManager).
    */
    protected function tableName(): string
    {
        return 'acquisition_ligne';
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
    
    /**
    * [REFACTOR #80 - Dette 10] Factory CategorieManager — mockable en test Unit.
    *
    * Aligne sur acquisitionManager() (#72). Permet de compter les
    * requêtes en Unit pour findByAcquisition() (#72) et findId() (#79).
    */
    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }
}