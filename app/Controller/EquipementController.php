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
        $emplacementManager = new EmplacementManager();
        
        // Récupérer les paramètres GET
        $categorie_id = $request->query->get('categorie');
        $filter_epi = $request->query->get('epi');
        $en_service = $request->query->get('en_service');
        $order_by = $request->query->get('order_by', 'categorie');
        $order_dir = $request->query->get('order_dir', 'asc');
        
        // ✅ Nettoyer les paramètres : une chaîne vide signifie "pas de filtre"
        if ($categorie_id === '') $categorie_id = null;
        if ($filter_epi === '') $filter_epi = null;
        if ($en_service === '') $en_service = null;
        
        // Récupérer les équipements
        $equipements = $equipementManager->findAll();
        
        // Charger les catégories et emplacements
        foreach ($equipements as $i => $equipement) {
            if (isset($equipement['categorie_id'])) {
                $equipements[$i]['categorie'] = $categorieManager->findId($equipement['categorie_id']);
            }
            if (isset($equipement['emplacement_id'])) {
                $equipements[$i]['emplacement'] = $emplacementManager->findId($equipement['emplacement_id']);
            }
        }
        
        // ✅ Filtre par catégorie
        if ($categorie_id) {
            $equipements = array_filter($equipements, function($e) use ($categorie_id) {
                return isset($e['categorie_id']) && $e['categorie_id'] == $categorie_id;
            });
        }
        
        // ✅ Filtre EPI / non EPI
        if ($filter_epi !== null) {
            $equipements = array_filter($equipements, function($e) use ($filter_epi) {
                return isset($e['categorie']['est_epi']) && $e['categorie']['est_epi'] == $filter_epi;
            });
        }
        
        // ✅ Filtre "En service" / "Hors service"
        if ($en_service !== null) {
            $today = date('Y-m-d');
            if ($en_service === 'oui') {
                // En service : date_fin > aujourd'hui OU non définie
                $equipements = array_filter($equipements, function($e) use ($today) {
                    return !isset($e['date_fin_utilisation']) || $e['date_fin_utilisation'] > $today;
                });
            } elseif ($en_service === 'non') {
                // Hors service : date_fin <= aujourd'hui
                $equipements = array_filter($equipements, function($e) use ($today) {
                    return isset($e['date_fin_utilisation']) && $e['date_fin_utilisation'] <= $today;
                });
            }
        }
        
        // ✅ Tri
        if ($order_by === 'categorie') {
            usort($equipements, function($a, $b) use ($order_dir) {
                $a_cat = $a['categorie']['libelle'] ?? '';
                $b_cat = $b['categorie']['libelle'] ?? '';
                return $order_dir === 'asc' ? strcmp($a_cat, $b_cat) : strcmp($b_cat, $a_cat);
            });
        } elseif ($order_by === 'date_fin') {
            usort($equipements, function($a, $b) use ($order_dir) {
                $a_date = $a['date_fin_utilisation'] ?? '9999-12-31';
                $b_date = $b['date_fin_utilisation'] ?? '9999-12-31';
                return $order_dir === 'asc' ? strcmp($a_date, $b_date) : strcmp($b_date, $a_date);
            });
        }
        
        // Récupérer toutes les catégories pour le filtre
        $categories = $categorieManager->findAll();
        
        return $this->render('equipement_list.twig', [
            'equipements' => array_values($equipements), // réindexer le tableau
            'categories' => $categories,
            'filtres' => [
                'categorie_id' => $categorie_id,
                'epi' => $filter_epi,
                'en_service' => $en_service,
                'order_by' => $order_by,
                'order_dir' => $order_dir,
            ]
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
