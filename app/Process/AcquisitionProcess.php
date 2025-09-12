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

        if ($ligne = $acquisitionLigneManager->findId($ligne_id)) {

            if ($ligne['equipements_generes'] === 1) {
                throw new \RuntimeException("Les équipements ont déjà été générés.", 1);
            }

            $equipementManager = new EquipementManager();

            for ($i = 1; $i <= $ligne['nombre']; $i++) {
                $new_equipement = [
                    'acquisition_id' => $ligne['acquisition_id'],
                    'categorie_id' => $ligne['categorie_id'],
                    'reference' => $ligne['reference'],
                    'libelle' => $ligne['designation'],
                    'code' => null,
                    'statut' => 0,
                    'remarques' => null,
                    'date_dernier_controle' => null,
                    'controle_en_cours' => 0
                ];

                $equipementManager->save($new_equipement);
            }

            return true;
        }

        throw new \RuntimeException("L'acquisition ligne n'existe pas", 1);
    }
}
