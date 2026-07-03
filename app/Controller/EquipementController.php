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
        $emplacement_id = $request->query->get('emplacement');
        $dernier_controle = $request->query->get('dernier_controle'); // ⬅️ NOUVEAU
        $order_by = $request->query->get('order_by', 'categorie');
        $order_dir = $request->query->get('order_dir', 'asc');
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 10);
        
        // Nettoyer les paramètres
        if ($categorie_id === '') $categorie_id = null;
        if ($filter_epi === '') $filter_epi = null;
        if ($en_service === '') $en_service = null;
        if ($emplacement_id === '') $emplacement_id = null;
        if ($dernier_controle === '') $dernier_controle = null;
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;
        
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
        
        // Filtres existants
        if ($categorie_id) {
            $equipements = array_filter($equipements, function($e) use ($categorie_id) {
                return isset($e['categorie_id']) && $e['categorie_id'] == $categorie_id;
            });
        }
        if ($filter_epi !== null) {
            $equipements = array_filter($equipements, function($e) use ($filter_epi) {
                return isset($e['categorie']['est_epi']) && $e['categorie']['est_epi'] == $filter_epi;
            });
        }
        if ($en_service !== null) {
            $today = date('Y-m-d');
            if ($en_service === 'oui') {
                $equipements = array_filter($equipements, function($e) use ($today) {
                    return !isset($e['date_fin_utilisation']) || $e['date_fin_utilisation'] > $today;
                });
            } elseif ($en_service === 'non') {
                $equipements = array_filter($equipements, function($e) use ($today) {
                    return isset($e['date_fin_utilisation']) && $e['date_fin_utilisation'] <= $today;
                });
            }
        }
        if ($emplacement_id !== null) {
            if ($emplacement_id === 'null') {
                $equipements = array_filter($equipements, function($e) {
                    return !isset($e['emplacement_id']) || $e['emplacement_id'] === null;
                });
            } else {
                $equipements = array_filter($equipements, function($e) use ($emplacement_id) {
                    return isset($e['emplacement_id']) && $e['emplacement_id'] == $emplacement_id;
                });
            }
        }
        
        // ⬇️ FILTRE : Dernier contrôle (version inversée)
        if ($dernier_controle !== null) {
            $oneYearAgo = date('Y-m-d', strtotime('-1 year'));
            if ($dernier_controle === 'plus_1_an') {
                // ✅ "Plus d'un an" → contrôle récent (moins d'un an)
                $equipements = array_filter($equipements, function($e) use ($oneYearAgo) {
                    return isset($e['date_dernier_controle']) && $e['date_dernier_controle'] >= $oneYearAgo;
                });
            } elseif ($dernier_controle === 'moins_1_an') {
                // ✅ "Moins d'un an" → contrôle ancien (plus d'un an ou NULL)
                $equipements = array_filter($equipements, function($e) use ($oneYearAgo) {
                    return !isset($e['date_dernier_controle']) || $e['date_dernier_controle'] < $oneYearAgo;
                });
            }
        }
        
        // Tri
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
        } elseif ($order_by === 'date_dernier_controle') {
            usort($equipements, function($a, $b) use ($order_dir) {
                $a_date = $a['date_dernier_controle'] ?? '1970-01-01';
                $b_date = $b['date_dernier_controle'] ?? '1970-01-01';
                return $order_dir === 'asc' ? strcmp($a_date, $b_date) : strcmp($b_date, $a_date);
            });
        }
        
        // Pagination
        $total = count($equipements);
        $offset = ($page - 1) * $limit;
        $equipements = array_slice($equipements, $offset, $limit);
        $totalPages = $limit > 0 ? ceil($total / $limit) : 1;
        
        // URLs de pagination
        $baseParams = [
            'categorie' => $categorie_id,
            'epi' => $filter_epi,
            'en_service' => $en_service,
            'emplacement' => $emplacement_id,
            'dernier_controle' => $dernier_controle,
            'order_by' => $order_by,
            'order_dir' => $order_dir,
            'limit' => $limit,
        ];
        $paginationUrls = [
            'first' => '?' . http_build_query(array_merge($baseParams, ['page' => 1])),
            'previous' => '?' . http_build_query(array_merge($baseParams, ['page' => max(1, $page - 1)])),
            'next' => '?' . http_build_query(array_merge($baseParams, ['page' => min($totalPages, $page + 1)])),
            'last' => '?' . http_build_query(array_merge($baseParams, ['page' => $totalPages])),
        ];
        
        // Récupérer les catégories et emplacements pour les filtres
        $categories = $categorieManager->findAll();
        $emplacements = $emplacementManager->findAll();
        
        return $this->render('equipement_list.twig', [
            'equipements' => array_values($equipements),
            'categories' => $categories,
            'emplacements' => $emplacements,
            'filtres' => [
                'categorie_id' => $categorie_id,
                'epi' => $filter_epi,
                'en_service' => $en_service,
                'emplacement_id' => $emplacement_id,
                'dernier_controle' => $dernier_controle,
                'order_by' => $order_by,
                'order_dir' => $order_dir,
                'page' => $page,
                'limit' => $limit,
            ],
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $totalPages,
                'hasPrevious' => $page > 1,
                'hasNext' => $page < $totalPages,
            ],
            'paginationUrls' => $paginationUrls,
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
            // Récupérer les valeurs et convertir les champs vides en null
            $emplacement_id = $request->request->get('emplacement_id');
            if ($emplacement_id === '') $emplacement_id = null;

            $date_mise_en_service = $request->request->get('date_mise_en_service');
            if ($date_mise_en_service === '') $date_mise_en_service = null;

            $date_fin_utilisation = $request->request->get('date_fin_utilisation');
            if ($date_fin_utilisation === '') $date_fin_utilisation = null;

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
                return $this->redirectTo("/equipements");
            }
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