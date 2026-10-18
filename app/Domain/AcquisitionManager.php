<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class AcquisitionManager extends AbstractManager
{
    /**
     * [MÉTIER] Valeur sentinelle de facture_document posée par l'import
     * Excel/CSV : la facture n'est pas encore téléversée et pourra
     * l'être ultérieurement (action dédiée sur l'acquisition validée).
     * Les templates comparent à ce littéral pour afficher un badge au
     * lieu d'un lien cassé.
     */
    public const FACTURE_A_IMPORTER = 'facture à importer';

    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
        . $this->buildLimitClause($limit, $offset);
        
        $sql = "SELECT * FROM acquisition $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $acquisitions = $stmt->fetchAll();
        
        // [REFACTOR #71] Early return : évite 2 requêtes IN vides.
        if ($acquisitions === []) {
            return $acquisitions;
        }
        
        // [REFACTOR #71] Collecte des IDs distincts non-null.
        // isset() (et non !== null) : reproduit exactement le test
        // historique — une colonne NULL ne doit PAS produire de clé
        // 'fournisseur' / 'redacteur' dans le résultat.
        $fournisseurIds = [];
        $utilisateurIds = [];
        foreach ($acquisitions as $a) {
            if (isset($a['fournisseur_id'])) {
                $fournisseurIds[$a['fournisseur_id']] = true;
            }
            if (isset($a['saisie_par'])) {
                $utilisateurIds[$a['saisie_par']] = true;
            }
        }
        
        $fournisseurs = $fournisseurIds === []
        ? []
        : $this->fournisseurManager()->findIds(array_keys($fournisseurIds));
        
        $utilisateurs = $utilisateurIds === []
        ? []
        // [SÉCURITÉ Dette 8] Projection publique : 'redacteur' est rendu
        // dans les vues Twig, sans password / reset_token.
        : $this->utilisateurManager()->findPublicIds(array_keys($utilisateurIds));
        
        // [REFACTOR #71] ?? null : FK orpheline = clé présente + valeur
        // null (comportement historique de findId() qui retournait null).
        foreach ($acquisitions as $i => $a) {
            if (isset($a['fournisseur_id'])) {
                $acquisitions[$i]['fournisseur'] = $fournisseurs[$a['fournisseur_id']] ?? null;
            }
            if (isset($a['saisie_par'])) {
                $acquisitions[$i]['redacteur'] = $utilisateurs[$a['saisie_par']] ?? null;
            }
        }
        
        return $acquisitions;
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM acquisition WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        if ($acquisition = $stmt->fetch()) {
            if (!isset($acquisition['est_validee'])) {
                $acquisition['est_validee'] = 0;
            }

            if (isset($acquisition['fournisseur_id'])) {
                $acquisition['fournisseur'] = $this->fournisseurManager()->findId($acquisition['fournisseur_id']);
            }
            
            if (isset($acquisition['saisie_par'])) {
                // [SÉCURITÉ Dette 8] Projection publique (sans password /
                // reset_token) : 'redacteur' est rendu dans les vues.
                $acquisition['redacteur'] = $this->utilisateurManager()->findPublicId($acquisition['saisie_par']);
            }

            return $acquisition;
        }

        return null;
    }

    /**
    * [REFACTOR #87] Recherche générique (AbstractManager) + hydratation
    * spécifique acquisition : 'fournisseur' et 'redacteur' (projection
    * publique Dette 8) sont ajoutés quand les FK sont présentes.
    * Iso-comportement avec la copie historique.
    */
    public function findOneByCriteria(array $criteria = [])
    {
        $acquisition = parent::findOneByCriteria($criteria);
        if ($acquisition === null) {
            return null;
        }

        if (isset($acquisition['fournisseur_id'])) {
            $acquisition['fournisseur'] = $this->fournisseurManager()->findId($acquisition['fournisseur_id']);
        }

        if (isset($acquisition['saisie_par'])) {
            // [SÉCURITÉ Dette 8] Projection publique (sans password /
            // reset_token) : 'redacteur' est rendu dans les vues.
            $acquisition['redacteur'] = $this->utilisateurManager()->findPublicId($acquisition['saisie_par']);
        }

        return $acquisition;
    }

    /**
    * [REFACTOR #87] Table pour parent::findOneByCriteria().
    */
    protected function tableName(): string
    {
        return 'acquisition';
    }

    public function save(array $acquisition)
    {
        if (isset($acquisition['id'])) {
            return $this->_patch($acquisition);
        }

        $this->_create($acquisition);
        return (int) $this->db->lastInsertId('acquisition');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM acquisition WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    /**
     * [REFACTOR #91] Champs autorisés en écriture (filteredInsert /
     * filteredUpdate — le '_patch' n'ajoute que 'id', géré par le helper).
     */
    private const ALLOWED_FIELDS = ['fournisseur_id', 'facture_reference', 'facture_date', 'facture_document', 'saisie_par', 'est_validee'];

    private function _create(array $acquisition)
    {
        return $this->filteredInsert($acquisition, self::ALLOWED_FIELDS);
    }

    private function _patch(array $acquisition)
    {
        return $this->filteredUpdate($acquisition, self::ALLOWED_FIELDS);
    }
    
    /**
    * [REFACTOR #80 - Dette 10] Factory FournisseurManager — mockable en test Unit.
    *
    * Aligne AcquisitionManager sur le pattern Vague 11 (cf.
    * AcquisitionLigneManager::acquisitionManager() #72).
    * Les sous-classes de test peuvent surcharger cette factory pour
    * injecter un mock et compter les requêtes.
    */
    protected function fournisseurManager(): FournisseurManager
    {
        return new FournisseurManager();
    }
    
    /**
    * [REFACTOR #80 - Dette 10] Factory UtilisateurManager — mockable en test Unit.
    */
    protected function utilisateurManager(): UtilisateurManager
    {
        return new UtilisateurManager();
    }
}