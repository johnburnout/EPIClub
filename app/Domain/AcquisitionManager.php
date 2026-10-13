<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class AcquisitionManager extends AbstractManager
{
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
        : (new FournisseurManager())->findIds(array_keys($fournisseurIds));
        
        $utilisateurs = $utilisateurIds === []
        ? []
        : (new UtilisateurManager())->findIds(array_keys($utilisateurIds));
        
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

            $fournisseurManager = new FournisseurManager();
            $utilisateurManager = new UtilisateurManager();

            if (isset($acquisition['fournisseur_id'])) {
                $acquisition['fournisseur'] = $fournisseurManager->findId($acquisition['fournisseur_id']);
            }

            if (isset($acquisition['saisie_par'])) {
                $acquisition['redacteur'] = $utilisateurManager->findId($acquisition['saisie_par']);
            }

            return $acquisition;
        }

        return null;
    }

    public function findOneByCriteria(array $criteria = [])
    {
        [$where, $params] = $this->buildWhereClause($criteria);
        
        $sql = "SELECT * FROM acquisition$where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($acquisition = $stmt->fetch()) {
            $fournisseurManager = new FournisseurManager();
            $utilisateurManager = new UtilisateurManager();

            if (isset($acquisition['fournisseur_id'])) {
                $acquisition['fournisseur'] = $fournisseurManager->findId($acquisition['fournisseur_id']);
            }

            if (isset($acquisition['saisie_par'])) {
                $acquisition['redacteur'] = $utilisateurManager->findId($acquisition['saisie_par']);
            }

            return $acquisition;
        }

        return null;
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

    private function _create(array $acquisition)
    {
        $allowedFields = ['fournisseur_id', 'facture_reference', 'facture_date', 'facture_document', 'saisie_par', 'est_validee'];
        $filteredAcquisition = array_intersect_key($acquisition, array_flip($allowedFields));

        $sql = "INSERT INTO acquisition (fournisseur_id, facture_reference, facture_date, facture_document, saisie_par, est_validee)
                VALUES (:fournisseur_id, :facture_reference, :facture_date, :facture_document, :saisie_par, :est_validee)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filteredAcquisition);
    }

    private function _patch(array $acquisition)
    {
        $allowedFields = ['fournisseur_id', 'facture_reference', 'facture_date', 'facture_document', 'saisie_par', 'est_validee', 'id'];
        $filteredAcquisition = array_intersect_key($acquisition, array_flip($allowedFields));

        $sql = "UPDATE acquisition
                SET fournisseur_id=:fournisseur_id, facture_reference=:facture_reference, facture_date=:facture_date,
                    facture_document=:facture_document, saisie_par=:saisie_par, est_validee=:est_validee
                WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($filteredAcquisition);

        return $result;
    }
}