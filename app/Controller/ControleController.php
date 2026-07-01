<?php

namespace Epiclub\Controller;

use Epiclub\Domain\ControleManager;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ControleController extends AbstractController
{
    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $controleManager = new ControleManager();
        $controles = $controleManager->findAll();

        return $this->render('controle_list.twig', [
            'controles' => $controles
        ]);
    }

    public function create(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');
        
        $user = $this->session->get('user');
        if (!empty($user['controle_en_cours_id'])) {
            $this->session->getFlashBag()->add('error', 'Vous avez déjà un contrôle en cours. Terminez-le avant d\'en créer un nouveau.');
            return $this->redirectTo('/admin/controles');
        }
        
        $controle = [
            'libelle' => 'Contrôle du ' . date('d/m/Y H:i'),
            'date_debut' => date('Y-m-d H:i:s'),
            'date_fin' => null,
            'statut' => 'ouvert',
            'controleur_id' => $user['id'],
            'cree_par' => $user['id'],
            'hash_remarques' => null
        ];
        $controleManager = new ControleManager();
        $id = $controleManager->save($controle);
        
        // Mise à jour de l'utilisateur
        $user['controle_en_cours_id'] = $id;
        $utilisateurManager = new UtilisateurManager();
        $utilisateurManager->save($user);
        $this->session->set('user', $user);
        
        $this->session->getFlashBag()->add('success', 'Contrôle créé avec succès.');
        return $this->redirectTo("/admin/controles/edit/$id");
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');
        
        $id = $request->get('id');
        $controleManager = new ControleManager();
        $controle = $controleManager->findId($id);
        
        if (!$controle) {
            return $this->redirectTo('/admin/controles');
        }
        
        if ($controle['statut'] === 'cloture') {
            $this->session->getFlashBag()->add('error', 'Ce contrôle est clôturé et ne peut plus être modifié.');
            return $this->redirectTo('/admin/controles');
        }
        
        $ligneManager = new ControleLigneManager();
        $lignes = $ligneManager->findByControle($id);
        $idsDejaAjoutes = array_column($lignes, 'equipement_id');
        
        // Paramètres GET
        $categorie_id = $request->query->get('categorie');
        $filter_epi = $request->query->get('epi');
        $en_service = $request->query->get('en_service');
        $emplacement_id = $request->query->get('emplacement');
        $order_by = $request->query->get('order_by', 'reference');
        $order_dir = $request->query->get('order_dir', 'asc');
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 10);
        
        // Nettoyer
        if ($categorie_id === '') $categorie_id = null;
        if ($filter_epi === '') $filter_epi = null;
        if ($en_service === '') $en_service = null;
        if ($emplacement_id === '') $emplacement_id = null;
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;
        
        // Récupérer tous les équipements
        $equipementManager = new EquipementManager();
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager();
        
        $tousLesEquipements = $equipementManager->findAll();
        foreach ($tousLesEquipements as $i => $e) {
            if (isset($e['categorie_id'])) {
                $tousLesEquipements[$i]['categorie'] = $categorieManager->findId($e['categorie_id']);
            }
            if (isset($e['emplacement_id'])) {
                $tousLesEquipements[$i]['emplacement'] = $emplacementManager->findId($e['emplacement_id']);
            }
        }
        
        // Filtrer les déjà ajoutés
        $equipementsDisponibles = array_filter($tousLesEquipements, function($e) use ($idsDejaAjoutes) {
            return !in_array($e['id'], $idsDejaAjoutes);
        });
        
        // Appliquer les filtres (identique à EquipementController)
        if ($categorie_id) {
            $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) use ($categorie_id) {
                return isset($e['categorie_id']) && $e['categorie_id'] == $categorie_id;
            });
        }
        if ($filter_epi !== null) {
            $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) use ($filter_epi) {
                return isset($e['categorie']['est_epi']) && $e['categorie']['est_epi'] == $filter_epi;
            });
        }
        if ($en_service !== null) {
            $today = date('Y-m-d');
            if ($en_service === 'oui') {
                $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) use ($today) {
                    return !isset($e['date_fin_utilisation']) || $e['date_fin_utilisation'] > $today;
                });
            } elseif ($en_service === 'non') {
                $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) use ($today) {
                    return isset($e['date_fin_utilisation']) && $e['date_fin_utilisation'] <= $today;
                });
            }
        }
        if ($emplacement_id !== null) {
            if ($emplacement_id === 'null') {
                $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) {
                    return !isset($e['emplacement_id']) || $e['emplacement_id'] === null;
                });
            } else {
                $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) use ($emplacement_id) {
                    return isset($e['emplacement_id']) && $e['emplacement_id'] == $emplacement_id;
                });
            }
        }
        
        // Tri
        if ($order_by === 'reference') {
            usort($equipementsDisponibles, function($a, $b) use ($order_dir) {
                return $order_dir === 'asc' ? strcmp($a['reference'], $b['reference']) : strcmp($b['reference'], $a['reference']);
            });
        } elseif ($order_by === 'libelle') {
            usort($equipementsDisponibles, function($a, $b) use ($order_dir) {
                return $order_dir === 'asc' ? strcmp($a['libelle'], $b['libelle']) : strcmp($b['libelle'], $a['libelle']);
            });
        } elseif ($order_by === 'categorie') {
            usort($equipementsDisponibles, function($a, $b) use ($order_dir) {
                $a_cat = $a['categorie']['libelle'] ?? '';
                $b_cat = $b['categorie']['libelle'] ?? '';
                return $order_dir === 'asc' ? strcmp($a_cat, $b_cat) : strcmp($b_cat, $a_cat);
            });
        }
        
        // Pagination
        $total = count($equipementsDisponibles);
        $offset = ($page - 1) * $limit;
        $equipementsDisponibles = array_slice($equipementsDisponibles, $offset, $limit);
        $totalPages = $limit > 0 ? ceil($total / $limit) : 1;
        
        // URLs de pagination
        $baseParams = [
            'categorie' => $categorie_id,
            'epi' => $filter_epi,
            'en_service' => $en_service,
            'emplacement' => $emplacement_id,
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
        
        // Récupérer les listes pour les filtres
        $categories = $categorieManager->findAll();
        $emplacements = $emplacementManager->findAll();
        
        return $this->render('controle_edit.twig', [
            'controle' => $controle,
            'lignes' => $lignes,
            'equipements_disponibles' => array_values($equipementsDisponibles),
            'categories' => $categories,
            'emplacements' => $emplacements,
            'filtres' => [
                'categorie_id' => $categorie_id,
                'epi' => $filter_epi,
                'en_service' => $en_service,
                'emplacement_id' => $emplacement_id,
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

    public function addEquipement(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $controle_id = $request->get('controle_id');
        $equipement_id = $request->request->get('equipement_id');

        $controleManager = new ControleManager();
        $controle = $controleManager->findId($controle_id);
        if (!$controle || $controle['statut'] === 'cloture') {
            return $this->redirectTo("/admin/controles/edit/$controle_id");
        }

        $ligneManager = new ControleLigneManager();
        $ligneManager->save([
            'controle_id' => $controle_id,
            'equipement_id' => $equipement_id,
            'remarque' => null,
            'date_controle' => null,
            'statut' => 'a_controler'
        ]);

        return $this->redirectTo("/admin/controles/edit/$controle_id");
    }

    public function updateLigne(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');
        
        $ligne_id = $request->get('id');
        $ligneManager = new ControleLigneManager();
        $ligne = $ligneManager->findId($ligne_id);
        
        if (!$ligne) {
            return $this->redirectTo('/admin/controles');
        }
        
        $controleManager = new ControleManager();
        $controle = $controleManager->findId($ligne['controle_id']);
        if ($controle['statut'] === 'cloture') {
            return $this->redirectTo("/admin/controles/edit/{$controle['id']}");
        }
        
        if ($request->getMethod() === 'POST') {
            $ligne['remarque'] = $request->request->get('remarque');
            $ligne['date_controle'] = $request->request->get('date_controle');
            $ligne['statut'] = $request->request->get('statut');
            $ligneManager->save($ligne);
            return $this->redirectTo("/admin/controles/edit/{$controle['id']}");
        }
        
        // ✅ Si la date de contrôle n'est pas définie, on la pré-remplit avec la date courante
        if (is_null($ligne['date_controle'])) {
            $ligne['date_controle'] = date('Y-m-d H:i:s');
        }
        
        return $this->render('controle_ligne_form.twig', [
            'ligne' => $ligne
        ]);
    }

    public function cloturer(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');
        
        $id = $request->get('id');
        $controleManager = new ControleManager();
        $controle = $controleManager->findId($id);
        
        if (!$controle || $controle['statut'] === 'cloture') {
            return $this->redirectTo('/admin/controles');
        }
        
        // Récupérer toutes les remarques pour les hasher
        $ligneManager = new ControleLigneManager();
        $lignes = $ligneManager->findByControle($id);
        $remarques = '';
        foreach ($lignes as $ligne) {
            $remarques .= $ligne['remarque'] . '|';
        }
        $hash = hash('sha256', $remarques);
        
        $controle['statut'] = 'cloture';
        $controle['date_fin'] = date('Y-m-d H:i:s');
        $controle['hash_remarques'] = $hash;
        $controleManager->save($controle);
        
        $user = $this->session->get('user');
        $user['controle_en_cours_id'] = null;
        $utilisateurManager = new UtilisateurManager();
        $utilisateurManager->save($user);
        $this->session->set('user', $user);
        
        $this->session->getFlashBag()->add('success', 'Contrôle clôturé avec succès.');
        return $this->redirectTo('/admin/controles');
    }
}