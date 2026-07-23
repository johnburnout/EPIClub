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
    /**
     * Vérifie si l'utilisateur courant peut modifier le contrôle.
     *
     * @param array $controle
     * @param array $user
     * @return bool
     */
    private function canEdit($controle, $user)
    {
        // Si clôturé => jamais modifiable
        if ($controle['statut'] === 'cloture') {
            return false;
        }
        
        // L'utilisateur est-il le propriétaire ?
        if ($controle['controleur_id'] == $user['id']) {
            return true;
        }
        
        // Sinon, est-il admin ?
        if ($this->isGranted('ROLE_ADMIN')) {
            $today = date('Y-m-d');
            $dateDebut = substr($controle['date_debut'], 0, 10);
            if ($dateDebut >= $today) {
                return false;
            }
            
            if ($this->isUserOnline($controle['controleur_id'])) {
                return false;
            }
            
            return true;
        }
        
        return false;
    }

    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');
        
        $user = $this->session->get('user');
        $controleManager = new ControleManager();
        $allControles = $controleManager->findAll();
        
        $statut = $request->query->get('statut');
        $controleur_id = $request->query->get('controleur_id');
        $order_by = $request->query->get('order_by', 'date_debut');
        $order_dir = $request->query->get('order_dir', 'desc');
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 10);
        
        if ($statut === '') $statut = null;
        if ($controleur_id === '') $controleur_id = null;
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;
        
        if ($statut !== null) {
            $allControles = array_filter($allControles, function($c) use ($statut) {
                return $c['statut'] == $statut;
            });
        }
        
        if ($controleur_id !== null) {
            $allControles = array_filter($allControles, function($c) use ($controleur_id) {
                return $c['controleur_id'] == $controleur_id;
            });
        }
        
        $today = date('Y-m-d');
        foreach ($allControles as &$controle) {
            $isOwner = ($controle['controleur_id'] == $user['id']);
            $isAdminEligible = $this->isGranted('ROLE_ADMIN')
                && $controle['statut'] !== 'cloture'
                && substr($controle['date_debut'], 0, 10) < $today
                && !$this->isUserOnline($controle['controleur_id']);
            
            $controle['canEdit'] = $isOwner || $isAdminEligible;
            $controle['isAdminEditable'] = !$isOwner && $isAdminEligible;
            $controle['isOwner'] = $isOwner;
            $controle['isOwnerOnline'] = $this->isUserOnline($controle['controleur_id']);
        }
        unset($controle);
        
        usort($allControles, function($a, $b) use ($order_by, $order_dir) {
            $valA = $a[$order_by] ?? '';
            $valB = $b[$order_by] ?? '';
            if ($order_dir === 'asc') {
                return strcmp((string)$valA, (string)$valB);
            } else {
                return strcmp((string)$valB, (string)$valA);
            }
        });
        
        $total = count($allControles);
        $offset = ($page - 1) * $limit;
        $controles = array_slice($allControles, $offset, $limit);
        $totalPages = $limit > 0 ? ceil($total / $limit) : 1;
        
        $baseParams = [
            'statut' => $statut,
            'controleur_id' => $controleur_id,
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
        
        $utilisateurManager = new UtilisateurManager();
        $controleurs = $utilisateurManager->findAll('nom ASC');
        
        return $this->render('controle_list.twig', [
            'controles' => $controles,
            'controleurs' => $controleurs,
            'filtres' => [
                'statut' => $statut,
                'controleur_id' => $controleur_id,
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
            'user' => $user,
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
        
        $user['controle_en_cours_id'] = $id;
        $utilisateurManager = new UtilisateurManager();
        $utilisateurManager->save($user);
        $this->session->set('user', $user);
        
        $this->session->getFlashBag()->add('success', 'Contrôle créé avec succès.');
        return $this->redirectTo("/admin/controles/edit/$id");
    }

    private function isUserOnline($user_id)
    {
        $utilisateurManager = new UtilisateurManager();
        $user = $utilisateurManager->findId($user_id);
        if (!$user || empty($user['last_activity'])) {
            return false;
        }
        $lastActivity = strtotime($user['last_activity']);
        $now = time();
        return ($now - $lastActivity) < 300; // 5 minutes
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
        
        $user = $this->session->get('user');
        $canEdit = $this->canEdit($controle, $user);
        $readonly = ($controle['statut'] === 'cloture' || !$canEdit);
        $isAdminEdit = ($controle['controleur_id'] != $user['id'] && $canEdit);

        // --- Gestion du POST pour la remarque générale ---
        if ($request->getMethod() === 'POST' && !$readonly) {
            $remarqueGenerale = $request->request->get('remarques_generales', '');
            $controle['hash_remarques'] = $remarqueGenerale;
            $controleManager->save($controle);
            $this->session->getFlashBag()->add('success', 'Remarques générales mises à jour.');
            return $this->redirectTo("/admin/controles/edit/$id");
        }
        // --- Fin de la gestion POST ---

        // ---- 1. Récupération des lignes (équipements déjà ajoutés) ----
        $ligneManager = new ControleLigneManager();
        $allLignes = $ligneManager->findByControle($id);
        
        $equipementManager = new EquipementManager();
        foreach ($allLignes as &$ligne) {
            $equipement = $equipementManager->findId($ligne['equipement_id']);
            if ($equipement) {
                $ligne['reference'] = $equipement['reference'] ?? '';
                $ligne['libelle'] = $equipement['libelle'] ?? '';
                $ligne['photo'] = $equipement['photo'] ?? null;
            } else {
                $ligne['reference'] = '';
                $ligne['libelle'] = '';
                $ligne['photo'] = null;
            }
        }
        unset($ligne);
        
        // Déchiffrement si clôturé (sur toutes les lignes)
        if ($readonly && $controle['statut'] === 'cloture') {
            $config = include __DIR__ . '/../../.env.local.php';
            $secretKey = isset($config['SECRET_KEY']) ? hex2bin($config['SECRET_KEY']) : null;
            $cipherMethod = $config['CIPHER_METHOD'] ?? 'AES-256-CBC';
            foreach ($allLignes as &$ligne) {
                if (!empty($ligne['remarque'])) {
                    $data = base64_decode($ligne['remarque'], true);
                    if ($data !== false) {
                        $ivLength = openssl_cipher_iv_length($cipherMethod);
                        if (strlen($data) >= $ivLength) {
                            $iv = substr($data, 0, $ivLength);
                            $chiffre = substr($data, $ivLength);
                            $decrypted = openssl_decrypt($chiffre, $cipherMethod, $secretKey, 0, $iv);
                            if ($decrypted !== false) {
                                $ligne['remarque'] = $decrypted;
                            }
                        }
                    }
                }
            }
            unset($ligne);
            
            // --- Déchiffrement de la remarque générale ---
            if (!empty($controle['hash_remarques'])) {
                $data = base64_decode($controle['hash_remarques'], true);
                if ($data !== false) {
                    $ivLength = openssl_cipher_iv_length($cipherMethod);
                    if (strlen($data) >= $ivLength) {
                        $iv = substr($data, 0, $ivLength);
                        $chiffre = substr($data, $ivLength);
                        $decrypted = openssl_decrypt($chiffre, $cipherMethod, $secretKey, 0, $iv);
                        if ($decrypted !== false) {
                            $controle['hash_remarques'] = $decrypted;
                        }
                    }
                }
            }
        }
        
        // ---- 2. Paramètres pour la liste des lignes ----
        $lignes_page = (int) $request->query->get('lignes_page', 1);
        $lignes_limit = (int) $request->query->get('lignes_limit', 10);
        $lignes_order_by = $request->query->get('lignes_order_by', 'reference');
        $lignes_order_dir = $request->query->get('lignes_order_dir', 'asc');
        $lignes_statut = $request->query->get('lignes_statut', null);
        $lignes_search = $request->query->get('lignes_search', '');
        
        if ($lignes_statut !== null && $lignes_statut !== '') {
            $allLignes = array_filter($allLignes, function($l) use ($lignes_statut) {
                return $l['statut'] == $lignes_statut;
            });
        }
        if (!empty($lignes_search)) {
            $search = strtolower(trim($lignes_search));
            $allLignes = array_filter($allLignes, function($l) use ($search) {
                return strpos(strtolower($l['reference']), $search) !== false 
                    || strpos(strtolower($l['libelle']), $search) !== false;
            });
        }
        
        usort($allLignes, function($a, $b) use ($lignes_order_by, $lignes_order_dir) {
            $valA = $a[$lignes_order_by] ?? '';
            $valB = $b[$lignes_order_by] ?? '';
            if ($lignes_order_dir === 'asc') {
                return strcmp((string)$valA, (string)$valB);
            } else {
                return strcmp((string)$valB, (string)$valA);
            }
        });
        
        $lignes_total = count($allLignes);
        $lignes_offset = ($lignes_page - 1) * $lignes_limit;
        $lignes = array_slice($allLignes, $lignes_offset, $lignes_limit);
        $lignes_totalPages = $lignes_limit > 0 ? ceil($lignes_total / $lignes_limit) : 1;
        
        $baseLignesParams = [
            'lignes_statut' => $lignes_statut,
            'lignes_search' => $lignes_search,
            'lignes_order_by' => $lignes_order_by,
            'lignes_order_dir' => $lignes_order_dir,
            'lignes_limit' => $lignes_limit,
        ];
        $lignesPaginationUrls = [
            'first' => '?' . http_build_query(array_merge($baseLignesParams, ['lignes_page' => 1])),
            'previous' => '?' . http_build_query(array_merge($baseLignesParams, ['lignes_page' => max(1, $lignes_page - 1)])),
            'next' => '?' . http_build_query(array_merge($baseLignesParams, ['lignes_page' => min($lignes_totalPages, $lignes_page + 1)])),
            'last' => '?' . http_build_query(array_merge($baseLignesParams, ['lignes_page' => $lignes_totalPages])),
        ];
        
        // ---- 3. Vérifier s'il reste des équipements "à contrôler" (sur toutes les lignes, avant filtrage) ----
        $hasPending = false;
        foreach ($allLignes as $ligne) {
            if ($ligne['statut'] === 'a_controler') {
                $hasPending = true;
                break;
            }
        }
        
        // ---- 4. Liste des équipements disponibles ----
        $idsDejaAjoutes = array_column($allLignes, 'equipement_id');
        
        $categorie_id = $request->query->get('categorie');
        $filter_epi = $request->query->get('epi');
        $en_service = $request->query->get('en_service');
        $emplacement_id = $request->query->get('emplacement');
        $dernier_controle = $request->query->get('dernier_controle');
        $order_by = $request->query->get('order_by', 'reference');
        $order_dir = $request->query->get('order_dir', 'asc');
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 10);
        
        if ($categorie_id === '') $categorie_id = null;
        if ($filter_epi === '') $filter_epi = null;
        if ($en_service === '') $en_service = null;
        if ($emplacement_id === '') $emplacement_id = null;
        if ($dernier_controle === '') $dernier_controle = null;
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;
        
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
        
        $equipementsDisponibles = array_filter($tousLesEquipements, function($e) use ($idsDejaAjoutes) {
            return !in_array($e['id'], $idsDejaAjoutes);
        });
        
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
        
        if ($dernier_controle !== null) {
            $oneYearAgo = date('Y-m-d', strtotime('-1 year'));
            if ($dernier_controle === 'plus_1_an') {
                $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) use ($oneYearAgo) {
                    return isset($e['date_dernier_controle']) && $e['date_dernier_controle'] >= $oneYearAgo;
                });
            } elseif ($dernier_controle === 'moins_1_an') {
                $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) use ($oneYearAgo) {
                    return !isset($e['date_dernier_controle']) || $e['date_dernier_controle'] < $oneYearAgo;
                });
            }
        }
        
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
        
        $total = count($equipementsDisponibles);
        $offset = ($page - 1) * $limit;
        $equipementsDisponibles = array_slice($equipementsDisponibles, $offset, $limit);
        $totalPages = $limit > 0 ? ceil($total / $limit) : 1;
        
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
            'lignes_filtres' => [
                'statut' => $lignes_statut,
                'search' => $lignes_search,
                'order_by' => $lignes_order_by,
                'order_dir' => $lignes_order_dir,
                'limit' => $lignes_limit,
            ],
            'lignes_pagination' => [
                'total' => $lignes_total,
                'page' => $lignes_page,
                'limit' => $lignes_limit,
                'totalPages' => $lignes_totalPages,
                'hasPrevious' => $lignes_page > 1,
                'hasNext' => $lignes_page < $lignes_totalPages,
            ],
            'lignes_paginationUrls' => $lignesPaginationUrls,
            'readonly' => $readonly,
            'hasPending' => $hasPending,
            'isAdminEdit' => $isAdminEdit,
            'user' => $user,
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

        $user = $this->session->get('user');
        if (!$this->canEdit($controle, $user)) {
            $this->session->getFlashBag()->add('error', 'Vous n\'avez pas les droits pour modifier ce contrôle.');
            return $this->redirectTo('/admin/controles');
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
        
        $user = $this->session->get('user');
        if (!$this->canEdit($controle, $user)) {
            $this->session->getFlashBag()->add('error', 'Vous n\'avez pas les droits pour modifier ce contrôle.');
            return $this->redirectTo('/admin/controles');
        }
        
        if ($controle['statut'] === 'cloture') {
            $this->session->getFlashBag()->add('error', 'Ce contrôle est clôturé, vous ne pouvez pas modifier les lignes.');
            return $this->redirectTo("/admin/controles/edit/{$controle['id']}");
        }
        
        if ($request->getMethod() === 'POST') {
            $ligne['remarque'] = $request->request->get('remarque');
            $ligne['date_controle'] = $request->request->get('date_controle');
            $ligne['statut'] = $request->request->get('statut');
            $ligneManager->save($ligne);
            return $this->redirectTo("/admin/controles/edit/{$controle['id']}");
        }
        
        if (is_null($ligne['date_controle'])) {
            $ligne['date_controle'] = date('Y-m-d H:i:s');
        }
        
        $equipementManager = new EquipementManager();
        $equipement = $equipementManager->findId($ligne['equipement_id']);
        if ($equipement) {
            $ligne['reference'] = $equipement['reference'] ?? '';
            $ligne['libelle'] = $equipement['libelle'] ?? '';
            $ligne['photo'] = $equipement['photo'] ?? null;
        } else {
            $ligne['reference'] = '';
            $ligne['libelle'] = '';
            $ligne['photo'] = null;
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
        
        $user = $this->session->get('user');
        if (!$this->canEdit($controle, $user)) {
            if ($this->isGranted('ROLE_ADMIN') && substr($controle['date_debut'], 0, 10) < date('Y-m-d')) {
                $this->session->getFlashBag()->add('error', 'Impossible de clôturer : le contrôleur est actuellement en ligne.');
            } else {
                $this->session->getFlashBag()->add('error', 'Vous n\'avez pas les droits pour clôturer ce contrôle.');
            }
            return $this->redirectTo('/admin/controles');
        }
        
        $ligneManager = new ControleLigneManager();
        $lignes = $ligneManager->findByControle($id);
        
        $config = include __DIR__ . '/../../.env.local.php';
        $secretKey = isset($config['SECRET_KEY']) ? hex2bin($config['SECRET_KEY']) : null;
        $cipherMethod = $config['CIPHER_METHOD'] ?? 'AES-256-CBC';
        
        // 1. Chiffrement de la remarque générale (stockée dans le champ hash_remarques)
        $remarqueGenerale = $controle['hash_remarques'] ?? '';
        if (!empty($remarqueGenerale)) {
            $ivLength = openssl_cipher_iv_length($cipherMethod);
            $iv = openssl_random_pseudo_bytes($ivLength);
            $chiffre = openssl_encrypt($remarqueGenerale, $cipherMethod, $secretKey, 0, $iv);
            $hashGlobal = base64_encode($iv . $chiffre);
        } else {
            $hashGlobal = null;
        }
        
        // 2. Chiffrement individuel de chaque remarque
        foreach ($lignes as $ligne) {
            if (!is_null($ligne['remarque']) && $ligne['remarque'] !== '') {
                $ivLength = openssl_cipher_iv_length($cipherMethod);
                $iv = openssl_random_pseudo_bytes($ivLength);
                $chiffre = openssl_encrypt($ligne['remarque'], $cipherMethod, $secretKey, 0, $iv);
                $ligne['remarque'] = base64_encode($iv . $chiffre);
                $ligneManager->save($ligne);
            }
        }
        
        // 3. Mise à jour du contrôle
        $controle['statut'] = 'cloture';
        $controle['date_fin'] = date('Y-m-d H:i:s');
        $controle['hash_remarques'] = $hashGlobal;
        $controleManager->save($controle);
        
        // 4. Nettoyage de la session
        if ($user['controle_en_cours_id'] == $id) {
            $user['controle_en_cours_id'] = null;
            $utilisateurManager = new UtilisateurManager();
            $utilisateurManager->save($user);
            $this->session->set('user', $user);
        }
        
        $this->session->getFlashBag()->add('success', 'Contrôle clôturé avec succès (remarques chiffrées).');
        return $this->redirectTo('/admin/controles');
    }
}