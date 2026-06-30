<?php

namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Enum\EquipementEtats;
use Epiclub\Enum\EquipementStatuts;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;

class EquipementController extends AbstractController
{
    public function list(Request $request)
    {
        $equipementManager = new EquipementManager();
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager(); // ✅ AJOUTER
        
        $equipements = $equipementManager->findAll();
        
        // ✅ Charger les catégories et les emplacements
        foreach ($equipements as $i => $equipement) {
            if (isset($equipement['categorie_id'])) {
                $equipements[$i]['categorie'] = $categorieManager->findId($equipement['categorie_id']);
            }
            if (isset($equipement['emplacement_id']) && $equipement['emplacement_id']) {
                $equipements[$i]['emplacement'] = $emplacementManager->findId($equipement['emplacement_id']);
            }
        }
        
        return $this->render('equipement_list.twig', [
            'equipements' => $equipements
        ]);
    }

    public function show(Request $request)
    {
        $equipementManager = new EquipementManager();
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager();
        
        $equipement = $equipementManager->findId($request->get('id'));
        
        if ($equipement) {
            if (isset($equipement['categorie_id'])) {
                $equipement['categorie'] = $categorieManager->findId($equipement['categorie_id']);
            }
            if (isset($equipement['emplacement_id'])) {
                $equipement['emplacement'] = $emplacementManager->findId($equipement['emplacement_id']);
            }
        }
        
        return $this->render('equipement_detail.twig', [
            'equipement' => $equipement
        ]);
    }
    
    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager();
        $equipementManager = new EquipementManager();
        $equipement = [];
        $form_errors = [];
        
        if ($id = $request->get('id')) {
            $equipement = $equipementManager->findId($id);
            if ($equipement && isset($equipement['categorie_id'])) {
                $equipement['categorie'] = $categorieManager->findId($equipement['categorie_id']);
            }
        }
        
        if ($request->getMethod() === 'POST') {
            /** @todo need validation here */
            
            // ✅ Récupérer les valeurs et convertir les champs vides en null
            $emplacement_id = $request->request->get('emplacement_id');
            if ($emplacement_id === '') {
                $emplacement_id = null;
            }
            
            $date_mise_en_service = $request->request->get('date_mise_en_service');
            if ($date_mise_en_service === '') {
                $date_mise_en_service = null;
            }
            
            $date_fin_utilisation = $request->request->get('date_fin_utilisation');
            if ($date_fin_utilisation === '') {
                $date_fin_utilisation = null;
            }
            
            if (empty($form_errors)) {
                $equipement = array_merge(
                    $equipement,
                    [
                        'statut_id' => $request->request->get('statut_id'),
                        'etat_usure_id' => $request->request->get('etat_usure_id'),
                        'emplacement_id' => $emplacement_id,
                        'remarques' => $request->request->get('remarques'),
                        'date_mise_en_service' => $date_mise_en_service,
                        'date_fin_utilisation' => $date_fin_utilisation,
                    ]
                );
                $equipementManager->save($equipement);
                /** @todo flash success */
                return $this->redirectTo("/equipements");
            }
            
            /** @todo else error something wrong... */
        }
        
        return $this->render('equipement_form.twig', [
            'categories' => $categorieManager->findAll(),
            'emplacements' => $emplacementManager->findAll(),
            'equipement_statuts' => EquipementStatuts::forSelect(),
            'equipement_etats' => EquipementEtats::forSelect(),
            'equipement' => $equipement,
            'form_errors' => $form_errors
        ]);
    }

    /**
     * @deprecated Why we need this?
     */
    public function delete(Request $request)
    {
        throw new \Exception("Error Processing Request", 1);
    }
}
