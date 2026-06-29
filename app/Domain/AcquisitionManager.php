<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class AcquisitionManager extends AbstractManager
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

        $sql = "SELECT * FROM acquisition $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $acquisitions = $stmt->fetchAll();

        $fournisseurManager = new FournisseurManager();
        $utilisateurManager = new UtilisateurManager();

        foreach ($acquisitions as $i => $acquisition) {
            if (isset($acquisition['fournisseur_id'])) {
                $acquisitions[$i]['fournisseur'] = $fournisseurManager->findId($acquisition['fournisseur_id']);
            }

            if (isset($acquisition['saisie_par'])) {
                $acquisitions[$i]['redacteur'] = $utilisateurManager->findId($acquisition['saisie_par']);
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
            // ✅ S'assurer que est_validee existe
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

        $sql = "SELECT * FROM acquisition $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);

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
            return $this->_update($acquisition);
        }
        
        $this->_insert($acquisition);
        return $this->db->lastInsertId('acquisition');
    }
    
    public function delete(int $id)
    {
        $sql = "DELETE FROM acquisition WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }
    
    private function _insert(array $acquisition)
    {
        if (!isset($acquisition['est_validee'])) {
            $acquisition['est_validee'] = 0;
        }
        
        $sql = "INSERT INTO acquisition (fournisseur_id, facture_reference, facture_date, facture_document, saisie_par, est_validee) 
        VALUES (:fournisseur_id, :facture_reference, :facture_date, :facture_document, :saisie_par, :est_validee)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($acquisition);
    }
    
    private function _update(array $acquisition)
    {
        // ✅ On ne garde que les champs attendus par la requête
        $allowedFields = ['fournisseur_id', 'facture_reference', 'facture_date', 'facture_document', 'saisie_par', 'est_validee', 'id'];
        $filteredAcquisition = array_intersect_key($acquisition, array_flip($allowedFields));
        
        if (!isset($filteredAcquisition['est_validee'])) {
            $filteredAcquisition['est_validee'] = 0;
        }
        
        $sql = "UPDATE acquisition 
        SET fournisseur_id=:fournisseur_id, facture_reference=:facture_reference, facture_date=:facture_date, 
        facture_document=:facture_document, saisie_par=:saisie_par, est_validee=:est_validee
        WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($filteredAcquisition);
        
        return $result;
    }
}