<?php

namespace Epiclub\Process;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;

class AcquisitionProcess
{
    public function acquisition_process($acquisition)
    {
        $acquisitionManager = new AcquisitionManager();
        $fournisseurManager = new FournisseurManager();

        /** @todo facture_document process here*/

        if ($fournisseur = $fournisseurManager->findOneByCriteria(['nom' => $acquisition['fournisseur_nom']])) {
            $fournisseur_id = $fournisseur['id'];
        } else {
            /** create new fournisseur */
            $fournisseur_id = $fournisseurManager->save(['nom' => $acquisition['fournisseur_nom']]);
        }

        $acquisition['fournisseur_id'] = $fournisseur_id;
        unset($acquisition['fournisseur_nom']);

        return $acquisitionManager->save($acquisition);

        /* foreach ($acquisition['lignes'] as $ligne) {
            $ligne['acquisition_id'] = $acquisition_id;
            $this->ligneProcess($ligne);
        } */
    }

    public function categorie_process(array $ligne)
    {
        $categorieManager = new CategorieManager();

        if ($categorie = $categorieManager->findOneByCriteria(['libelle' => $ligne['categorie_libelle']])) {
            $categorie_id = $categorie['id'];
        } else {
            /** create new equipement_categorie */
            $new_categorie = [
                'libelle' => ucfirst($ligne['categorie_libelle']),
                'description' => '',
                'image' => '',
                'est_epi' => 1
            ];

            $categorie_id = $categorieManager->save($new_categorie);
        }

        return $categorie_id;
    }

    public function create_equipement_process(int $ligne_id)
    {
        $acquisitionLigneManager = new AcquisitionLigneManager();
        $acquisitionManager = new AcquisitionManager();
        
        if ($ligne = $acquisitionLigneManager->findId($ligne_id)) {
            
            if ($ligne['equipements_generes'] === 1) {
                throw new \RuntimeException("Les équipements ont déjà été générés.", 1);
            }
            
            $acquisition = $acquisitionManager->findId($ligne['acquisition_id']);
            $annee = date('Y', strtotime($acquisition['facture_date']));
            
            $equipementManager = new EquipementManager();
            
            for ($i = 1; $i <= $ligne['nombre']; $i++) {
                $code = $annee . '-' . str_pad($ligne['acquisition_id'], 3, '0', STR_PAD_LEFT) . '-' . 
                str_pad($ligne['id'], 3, '0', STR_PAD_LEFT) . '-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                
                while ($equipementManager->codeExists($code)) {
                    $code = $code . '-' . rand(10, 99);
                }
                
                $new_equipement = [
                    'acquisition_id' => $ligne['acquisition_id'],
                    'categorie_id' => $ligne['categorie_id'],
                    'reference' => $ligne['reference'],
                    'libelle' => $ligne['designation'],
                    'code' => $code,
                    'statut' => 0,
                    'remarques' => null,
                    'date_dernier_controle' => null,
                    'controle_en_cours' => 0
                ];
                
                $equipementManager->save($new_equipement);
            }
            
            $ligne['equipements_generes'] = 1;
            $acquisitionLigneManager->save($ligne);
            
            return true;
        }
        
        throw new \RuntimeException("L'acquisition ligne n'existe pas", 1);
    }
    
    public function validerAcquisition(int $acquisitionId): bool
    {
        $acquisitionLigneManager = new AcquisitionLigneManager();
        $lignes = $acquisitionLigneManager->findByAcquisition($acquisitionId);
        
        $lignesNonGenerees = array_filter($lignes, function($ligne) {
            return $ligne['equipements_generes'] == 0;
        });
        
        if (empty($lignesNonGenerees)) {
            return false;  // ✅ Supprime exit;
        }
        
        foreach ($lignesNonGenerees as $ligne) {
            $this->create_equipement_process($ligne['id']);
        }
        
        // ✅ MISE À JOUR DE TOUTES LES LIGNES
        foreach ($lignesNonGenerees as $ligne) {
            $ligne['equipements_generes'] = 1;
            $acquisitionLigneManager->save($ligne);
        }
        
        // ✅ MISE À JOUR DE est_validee
        $acquisitionManager = new AcquisitionManager();
        $acquisition = $acquisitionManager->findId($acquisitionId);
        if ($acquisition) {
            $acquisition['est_validee'] = 1;
            $acquisitionManager->save($acquisition);
        }
        
        return true;
    }
}
    