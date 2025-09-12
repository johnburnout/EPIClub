<?php

namespace Epiclub\Process;

use Epiclub\Domain\EquipementCategorieManager;

class CreateAcquisitionLigneProcess
{
    public function __invoke($acquisition_lignes)
    {
        /* $categorieManager = new EquipementCategorieManager();

        foreach ($acquisition_lignes as $i => $ligne) {
            if ($categorie = $categorieManager->findOneByCriteria($ligne['categorie_nom'])) {
                $categorie_id = $categorie['id'];
            } else {
                $categorie_id = $categorieManager->save(['nom' => $ligne['categorie_nom']]);
            }

            $acquisition_lignes['categorie_id'] = $categorie_id;
        } */
    }
}
