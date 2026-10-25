<?php

namespace Epiclub\Controller;

use Epiclub\Enum\EquipementStatut;
use Epiclub\Domain\DureeDeVie;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\Pagination;
use Epiclub\Enum\ControleLigneStatut;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class ControleController extends AbstractController
{
    // ==================================================================
    // [REFACTOR VAGUE 11] Factories managers — mockables en test Unit.
    //
    // Chaque méthode retourne une nouvelle instance concrète.
    // Les sous-classes de test (TestableControleController) surchargent
    // ces factories pour injecter des mocks et éviter l'ouverture PDO
    // déclenchée par AbstractManager::__construct().
    // ==================================================================
    
    protected function controleManager(): ControleManager
    {
        return new ControleManager();
    }
    
    protected function controleLigneManager(): ControleLigneManager
    {
        return new ControleLigneManager();
    }
    
    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }
    
    protected function utilisateurManager(): UtilisateurManager
    {
        return new UtilisateurManager();
    }
    
    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }
    
    protected function emplacementManager(): EmplacementManager      // ← NOUVEAU
    {
        return new EmplacementManager();
    }    
    
    // ==================================================================
    // [SÉCURITÉ #52 / VAGUE 13 - #50b] Chiffrement symétrique des remarques.
    //
    // Délégation vers EncryptionService (Vague 13). Les helpers restent
    // `protected` pour préserver l'API interne du contrôleur : les ~12
    // appels internes ($this->encryptRemarque(...), etc.) sont inchangés.
    //
    // ─── Politique stricte ───
    //   SECRET_KEY absente ou invalide → RuntimeException (levée par
    //   EncryptionService au constructeur).
    // ==================================================================
    
    /**
    * Chiffre une remarque libre. Retourne null si la valeur est vide.
    *
    * @throws \RuntimeException si SECRET_KEY est absente ou invalide.
    */
    protected function encryptRemarque(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        return $this->encryptionService()->encrypt($plain);
    }
    
    /**
    * Déchiffre une remarque libre.
    * Retourne null si la valeur n'est pas un ciphertext valide.
    */
    protected function decryptRemarque(?string $encoded): ?string
    {
        return $this->encryptionService()->decrypt($encoded);
    }
    
    /**
    * Détecte si une valeur est déjà chiffrée par notre schéma.
    */
    protected function isEncrypted(?string $value): bool
    {
        return $this->encryptionService()->isEncrypted($value);
    }
    
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

        // ✅ Vérifier si l'utilisateur a un contrôle en cours qui est clôturé
        if (!empty($user['controle_en_cours_id'])) {
            $controleManager = $this->controleManager();
            $controle = $controleManager->findId($user['controle_en_cours_id']);
            if (!$controle || $controle['statut'] === 'cloture') {
                // Le contrôle n'existe plus ou est clôturé : on réinitialise
                $user['controle_en_cours_id'] = null;
                $utilisateurManager = $this->utilisateurManager();
                $utilisateurManager->save($user);
                $this->session->set('user', $user);
            }
        }

        $controleManager = $this->controleManager();
        $allControles = $controleManager->findAll();

        $statut = $request->query->get('statut');
        $controleur_id = $request->query->get('controleur_id');
        $order_by = $request->query->get('order_by', 'date_debut');
        $order_dir = $request->query->get('order_dir', 'desc');
        $page = (int) $request->query->get('page', 1);
        $limit = Pagination::limite($request->query->get('limit'));

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
        $ligneManager = $this->controleLigneManager();

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

            // --- Vérifier si des équipements sont encore "à contrôler" ---
            if ($controle['statut'] !== 'cloture') {
                $lignes = $ligneManager->findByControle($controle['id']);
                $hasPending = false;
                foreach ($lignes as $ligne) {
                    if ($ligne['statut'] === 'a_controler') {
                        $hasPending = true;
                        break;
                    }
                }
                $controle['hasPending'] = $hasPending;
            } else {
                $controle['hasPending'] = false;
            }
            // --- FIN ---
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

        $utilisateurManager = $this->utilisateurManager();
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

        // [SÉCURITÉ] La route est POST-only (cf. routes.php). Garde en
        // ceinture+bretelles si elle venait à être élargie par erreur.
        if (!$request->isMethod('POST')) {
            return $this->redirectTo('/admin/controles');
        }
        
        // [SÉCURITÉ] Vérification CSRF
        $this->validateCsrf($request);

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
        $controleManager = $this->controleManager();
        $id = $controleManager->save($controle);

        $user['controle_en_cours_id'] = $id;
        $utilisateurManager = $this->utilisateurManager();
        $utilisateurManager->save($user);
        $this->session->set('user', $user);

        $this->session->getFlashBag()->add('success', 'Contrôle créé avec succès.');
        return $this->redirectTo("/admin/controles/edit/$id");
    }

    private function isUserOnline($user_id)
    {
        $utilisateurManager = $this->utilisateurManager();
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

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return $this->redirectTo('/admin/controles');
        }

        $controleManager = $this->controleManager();
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
            return $this->handleEditRemarquePost($request, $controleManager, $controle, $id);
        }
        // --- Fin de la gestion POST ---

        // ---- 1. Récupération et préparation des lignes (équipements déjà ajoutés) ----
        $allLignes = $this->controleLigneManager()->findByControle($id);
        $allLignes = $this->enrichLignesWithEquipements($allLignes, $this->equipementManager());
        $allLignes = $this->decryptLignesRemarques($allLignes);
        $controle = $this->decryptControleRemarque($controle);

        // [#113 / #114] hasPending et idsDejaAjoutes calculés AVANT
        // filtrage — alignés sur index() (liste) et la garde serveur
        // cloturer(). Décision produit du 2026-10-18 (option A) : un
        // filtre actif ne doit ni activer le bouton « Clôturer » (#113)
        // ni re-proposer un équipement déjà ajouté (#114, doublons de
        // lignes possibles sinon).
        $hasPending = $this->hasPendingLignes($allLignes);
        $idsDejaAjoutes = array_column($allLignes, 'equipement_id');

        // ---- 2. Paramètres, filtrage, tri et pagination des lignes ----
        $lignesParams = $this->parseLignesQueryParams($request);
        $allLignes = $this->filterLignes($allLignes, $lignesParams['statut'], $lignesParams['search']);
        $allLignes = $this->sortLignesBy($allLignes, $lignesParams['order_by'], $lignesParams['order_dir']);

        [$lignes, $lignes_total, $lignes_totalPages] = $this->paginate(
            $allLignes,
            $lignesParams['page'],
            $lignesParams['limit']
        );
        $lignesPaginationUrls = $this->buildPaginationUrls(
            [
                'lignes_statut'   => $lignesParams['statut'],
                'lignes_search'   => $lignesParams['search'],
                'lignes_order_by' => $lignesParams['order_by'],
                'lignes_order_dir'=> $lignesParams['order_dir'],
                'lignes_limit'    => $lignesParams['limit'],
            ],
            $lignesParams['page'],
            $lignes_totalPages,
            'lignes_page'
        );

        // ---- 3. Liste des équipements disponibles ----
        $filtres = $this->parseEquipementQueryParams($request);

        $categorieManager = $this->categorieManager();
        $emplacementManager = $this->emplacementManager();

        $tousLesEquipements = $this->equipementManager()->findAll();
        $tousLesEquipements = $this->enrichEquipementsWithRelations(
            $tousLesEquipements,
            $categorieManager,
            $emplacementManager
        );

        $equipementsDisponibles = $this->filterEquipementsDisponibles($tousLesEquipements, $idsDejaAjoutes, $filtres);
        $equipementsDisponibles = $this->sortEquipementsBy(
            $equipementsDisponibles,
            $filtres['order_by'],
            $filtres['order_dir']
        );

        [$equipementsPage, $total, $totalPages] = $this->paginate(
            $equipementsDisponibles,
            $filtres['page'],
            $filtres['limit']
        );
        $paginationUrls = $this->buildPaginationUrls(
            [
                'categorie'        => $filtres['categorie_id'],
                'epi'              => $filtres['epi'],
                'en_service'       => $filtres['en_service'],
                'emplacement'      => $filtres['emplacement_id'],
                'dernier_controle' => $filtres['dernier_controle'],
                'order_by'         => $filtres['order_by'],
                'order_dir'        => $filtres['order_dir'],
                'limit'            => $filtres['limit'],
            ],
            $filtres['page'],
            $totalPages,
            'page'
        );

        $categories = $categorieManager->findAll();
        $emplacements = $emplacementManager->findAll();

        return $this->render('controle_edit.twig', [
            'controle' => $controle,
            'lignes' => $lignes,
            'equipements_disponibles' => array_values($equipementsPage),
            'categories' => $categories,
            'emplacements' => $emplacements,
            'filtres' => [
                'categorie_id' => $filtres['categorie_id'],
                'epi' => $filtres['epi'],
                'en_service' => $filtres['en_service'],
                'emplacement_id' => $filtres['emplacement_id'],
                'dernier_controle' => $filtres['dernier_controle'],
                'order_by' => $filtres['order_by'],
                'order_dir' => $filtres['order_dir'],
                'page' => $filtres['page'],
                'limit' => $filtres['limit'],
            ],
            'pagination' => [
                'total' => $total,
                'page' => $filtres['page'],
                'limit' => $filtres['limit'],
                'totalPages' => $totalPages,
                'hasPrevious' => $filtres['page'] > 1,
                'hasNext' => $filtres['page'] < $totalPages,
            ],
            'paginationUrls' => $paginationUrls,
            'lignes_filtres' => [
                'statut' => $lignesParams['statut'],
                'search' => $lignesParams['search'],
                'order_by' => $lignesParams['order_by'],
                'order_dir' => $lignesParams['order_dir'],
                'limit' => $lignesParams['limit'],
            ],
            'lignes_pagination' => [
                'total' => $lignes_total,
                'page' => $lignesParams['page'],
                'limit' => $lignesParams['limit'],
                'totalPages' => $lignes_totalPages,
                'hasPrevious' => $lignesParams['page'] > 1,
                'hasNext' => $lignesParams['page'] < $lignes_totalPages,
            ],
            'lignes_paginationUrls' => $lignesPaginationUrls,
            'readonly' => $readonly,
            'hasPending' => $hasPending,
            'isAdminEdit' => $isAdminEdit,
            'user' => $user,
        ]);
    }

    // ==================================================================
    // [#111] Handlers privés de edit() — découpés iso-comportement
    // (Priorité 4 de l'audit : « ControleController::edit() : découper en
    // handlers »). Aucune transformation : mouvements mécaniques uniquement.
    // ==================================================================

    /**
     * POST remarques générales de edit() : CSRF, chiffrement #52, save,
     * flash et redirection.
     */
    private function handleEditRemarquePost(
        Request $request,
        ControleManager $controleManager,
        array $controle,
        int $id
    ): Response {
        // [SÉCURITÉ] Vérification CSRF avant enregistrement des remarques
        $this->validateCsrf($request);

        // [SÉCURITÉ #52] Chiffrement dès l'écriture. La valeur n'est plus
        // jamais stockée en clair, même avant clôture du contrôle.
        $remarqueGenerale = $request->request->get('remarques_generales', '');
        $controle['hash_remarques'] = $this->encryptRemarque(
            is_string($remarqueGenerale) ? $remarqueGenerale : ''
        );
        $controleManager->save($controle);
        $this->session->getFlashBag()->add('success', 'Remarques générales mises à jour.');
        return $this->redirectTo("/admin/controles/edit/$id");
    }

    /**
     * Enrichit chaque ligne avec référence/libellé/photo de son équipement.
     * [REFACTOR #112] N+1 supprimé : un findIds() batch remplace le
     * findId() par ligne. Iso-comportement : équipement introuvable ou
     * soft-deleted → reference/libelle vides, photo null (comme le
     * findId() qui retournait null) ; ordre des lignes préservé.
     */
    private function enrichLignesWithEquipements(array $allLignes, EquipementManager $equipementManager): array
    {
        // Collecte des IDs distincts (dédoublonnage via clés de map).
        $equipementIds = [];
        foreach ($allLignes as $ligne) {
            $equipementIds[$ligne['equipement_id']] = true;
        }

        // Early return implicite : pas de lignes → pas de requête,
        // comme avant (la boucle findId() ne tournait pas).
        $equipements = $equipementIds === []
            ? []
            : $equipementManager->findIds(array_keys($equipementIds));

        foreach ($allLignes as &$ligne) {
            // ?? null : FK orpheline / équipement soft-deleted → même
            // branche que le findId() qui retournait null.
            $equipement = $equipements[$ligne['equipement_id']] ?? null;
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

        return $allLignes;
    }

    /**
     * [SÉCURITÉ #52] Déchiffrement en lecture des remarques de lignes.
     *
     * Les lignes peuvent être chiffrées après une clôture, mais tant
     * qu'elles sont en clair, decryptRemarque() retourne null et on
     * garde la valeur d'origine.
     */
    private function decryptLignesRemarques(array $allLignes): array
    {
        foreach ($allLignes as &$ligne) {
            if (!empty($ligne['remarque'])) {
                $decrypted = $this->decryptRemarque($ligne['remarque']);
                if ($decrypted !== null) {
                    $ligne['remarque'] = $decrypted;
                }
            }
        }
        unset($ligne);

        return $allLignes;
    }

    /**
     * [SÉCURITÉ #52] Déchiffrement de la remarque générale du contrôle.
     * Chiffrée dès l'écriture par edit() POST, elle est déchiffrée à
     * CHAQUE affichage du formulaire, clôturé ou non.
     */
    private function decryptControleRemarque(array $controle): array
    {
        if (!empty($controle['hash_remarques'])) {
            $decrypted = $this->decryptRemarque($controle['hash_remarques']);
            if ($decrypted !== null) {
                $controle['hash_remarques'] = $decrypted;
            }
        }

        return $controle;
    }

    /**
     * Paramètres query de la liste des lignes.
     *
     * @return array{page: int, limit: int, order_by: string, order_dir: string, statut: ?string, search: string}
     */
    private function parseLignesQueryParams(Request $request): array
    {
        return [
            'page'      => (int) $request->query->get('lignes_page', 1),
            'limit'     => Pagination::limite($request->query->get('lignes_limit')),
            'order_by'  => $request->query->get('lignes_order_by', 'reference'),
            'order_dir' => $request->query->get('lignes_order_dir', 'asc'),
            'statut'    => $request->query->get('lignes_statut', null),
            'search'    => $request->query->get('lignes_search', ''),
        ];
    }

    /**
     * Filtres statut + recherche (référence/libellé) des lignes.
     */
    private function filterLignes(array $allLignes, ?string $lignes_statut, string $lignes_search): array
    {
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

        return $allLignes;
    }

    /**
     * Tri des lignes par colonne (strcmp sur valeurs stringifiées).
     */
    private function sortLignesBy(array $lignes, string $orderBy, string $orderDir): array
    {
        usort($lignes, function($a, $b) use ($orderBy, $orderDir) {
            $valA = $a[$orderBy] ?? '';
            $valB = $b[$orderBy] ?? '';
            if ($orderDir === 'asc') {
                return strcmp((string)$valA, (string)$valB);
            } else {
                return strcmp((string)$valB, (string)$valA);
            }
        });

        return $lignes;
    }

    /**
     * Découpe une liste filtrée en page. Bloc identique utilisé pour les
     * lignes et les équipements disponibles.
     *
     * @return array{0: array, 1: int, 2: float|int} [slice, total, totalPages]
     */
    private function paginate(array $items, int $page, int $limit): array
    {
        $total = count($items);
        $offset = ($page - 1) * $limit;

        return [
            array_slice($items, $offset, $limit),
            $total,
            $limit > 0 ? ceil($total / $limit) : 1,
        ];
    }

    /**
     * URLs de pagination first/previous/next/last. Bloc identique utilisé
     * pour les lignes et les équipements disponibles.
     *
     * @param string $pageParam Nom du paramètre query de la page ('page'
     *                          ou 'lignes_page').
     */
    private function buildPaginationUrls(array $baseParams, int $page, int $totalPages, string $pageParam): array
    {
        return [
            'first'    => '?' . http_build_query(array_merge($baseParams, [$pageParam => 1])),
            'previous' => '?' . http_build_query(array_merge($baseParams, [$pageParam => max(1, $page - 1)])),
            'next'     => '?' . http_build_query(array_merge($baseParams, [$pageParam => min($totalPages, $page + 1)])),
            'last'     => '?' . http_build_query(array_merge($baseParams, [$pageParam => $totalPages])),
        ];
    }

    /**
     * True si au moins une ligne est "à contrôler" (statut a_controler).
     */
    private function hasPendingLignes(array $allLignes): bool
    {
        foreach ($allLignes as $ligne) {
            if ($ligne['statut'] === 'a_controler') {
                return true;
            }
        }

        return false;
    }

    /**
     * Paramètres query de la liste des équipements disponibles
     * (chaînes vides normalisées en null, page/limit bornés).
     *
     * @return array{categorie_id: ?string, epi: ?string, en_service: ?string, emplacement_id: ?string, dernier_controle: ?string, order_by: string, order_dir: string, page: int, limit: int}
     */
    private function parseEquipementQueryParams(Request $request): array
    {
        $categorie_id = $request->query->get('categorie');
        $filter_epi = $request->query->get('epi');
        $en_service = $request->query->get('en_service');
        $emplacement_id = $request->query->get('emplacement');
        $dernier_controle = $request->query->get('dernier_controle');
        $order_by = $request->query->get('order_by', 'reference');
        $order_dir = $request->query->get('order_dir', 'asc');
        $page = (int) $request->query->get('page', 1);
        $limit = Pagination::limite($request->query->get('limit'));

        if ($categorie_id === '') $categorie_id = null;
        if ($filter_epi === '') $filter_epi = null;
        if ($en_service === '') $en_service = null;
        if ($emplacement_id === '') $emplacement_id = null;
        if ($dernier_controle === '') $dernier_controle = null;
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;

        return [
            'categorie_id'     => $categorie_id,
            'epi'              => $filter_epi,
            'en_service'       => $en_service,
            'emplacement_id'   => $emplacement_id,
            'dernier_controle' => $dernier_controle,
            'order_by'         => $order_by,
            'order_dir'        => $order_dir,
            'page'             => $page,
            'limit'            => $limit,
        ];
    }

    /**
     * Enrichit chaque équipement de sa catégorie et de son emplacement.
     * [REFACTOR #112] N+1 supprimé : un findIds() batch par relation
     * remplace les findId() par FK. Iso-comportement : isset() conservé
     * (clé 'categorie'/'emplacement' absente si FK non renseignée),
     * ?? null sur FK orpheline (comme le findId() qui retournait null),
     * ordre préservé.
     */
    private function enrichEquipementsWithRelations(
        array $tousLesEquipements,
        CategorieManager $categorieManager,
        EmplacementManager $emplacementManager
    ): array {
        // Collecte des IDs distincts non-null (dédoublonnage via clés).
        $categorieIds = [];
        $emplacementIds = [];
        foreach ($tousLesEquipements as $e) {
            if (isset($e['categorie_id'])) {
                $categorieIds[$e['categorie_id']] = true;
            }
            if (isset($e['emplacement_id'])) {
                $emplacementIds[$e['emplacement_id']] = true;
            }
        }

        $categories = $categorieIds === []
            ? []
            : $categorieManager->findIds(array_keys($categorieIds));
        $emplacements = $emplacementIds === []
            ? []
            : $emplacementManager->findIds(array_keys($emplacementIds));

        foreach ($tousLesEquipements as $i => $e) {
            if (isset($e['categorie_id'])) {
                $tousLesEquipements[$i]['categorie'] = $categories[$e['categorie_id']] ?? null;
            }
            if (isset($e['emplacement_id'])) {
                $tousLesEquipements[$i]['emplacement'] = $emplacements[$e['emplacement_id']] ?? null;
            }
        }

        return $tousLesEquipements;
    }

    /**
     * Filtres de la liste des équipements disponibles : retrait des déjà
     * ajoutés, catégorie, EPI, en service, emplacement, dernier contrôle.
     * Sémantique conservée telle quelle (y compris les cas connus).
     */
    private function filterEquipementsDisponibles(array $equipementsDisponibles, array $idsDejaAjoutes, array $filtres): array
    {
        $categorie_id = $filtres['categorie_id'];
        $filter_epi = $filtres['epi'];
        $en_service = $filtres['en_service'];
        $emplacement_id = $filtres['emplacement_id'];
        $dernier_controle = $filtres['dernier_controle'];

        // [Prêt de matériel] Un matériel prêté n'est pas contrôlable.
        $equipementsDisponibles = array_filter($equipementsDisponibles, function($e) use ($idsDejaAjoutes) {
            return !in_array($e['id'], $idsDejaAjoutes)
                && (int) ($e['statut'] ?? 0) !== EquipementStatut::PRETE->value;
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

        return $equipementsDisponibles;
    }

    /**
     * Tri de la liste des équipements disponibles (reference/libelle/categorie).
     */
    private function sortEquipementsBy(array $equipementsDisponibles, string $orderBy, string $orderDir): array
    {
        if ($orderBy === 'reference') {
            usort($equipementsDisponibles, function($a, $b) use ($orderDir) {
                return $orderDir === 'asc' ? strcmp($a['reference'], $b['reference']) : strcmp($b['reference'], $a['reference']);
            });
        } elseif ($orderBy === 'libelle') {
            usort($equipementsDisponibles, function($a, $b) use ($orderDir) {
                return $orderDir === 'asc' ? strcmp($a['libelle'], $b['libelle']) : strcmp($b['libelle'], $a['libelle']);
            });
        } elseif ($orderBy === 'categorie') {
            usort($equipementsDisponibles, function($a, $b) use ($orderDir) {
                $a_cat = $a['categorie']['libelle'] ?? '';
                $b_cat = $b['categorie']['libelle'] ?? '';
                return $orderDir === 'asc' ? strcmp($a_cat, $b_cat) : strcmp($b_cat, $a_cat);
            });
        }

        return $equipementsDisponibles;
    }

    public function addEquipement(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        // [SÉCURITÉ] Vérification CSRF (route POST-only)
        $this->validateCsrf($request);

        $controle_id = $this->getValidId($request, 'controle_id');
        if ($controle_id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return $this->redirectTo('/admin/controles');
        }

        $equipement_id = $this->getValidId($request, 'equipement_id');
        if ($equipement_id === null) {
            // Formulaire trafiqué : on revient silencieusement sur le contrôle
            return $this->redirectTo("/admin/controles/edit/$controle_id");
        }

        $controleManager = $this->controleManager();
        $controle = $controleManager->findId($controle_id);
        if (!$controle || $controle['statut'] === 'cloture') {
            return $this->redirectTo("/admin/controles/edit/$controle_id");
        }

        $user = $this->session->get('user');
        if (!$this->canEdit($controle, $user)) {
            $this->session->getFlashBag()->add('error', 'Vous n\'avez pas les droits pour modifier ce contrôle.');
            return $this->redirectTo('/admin/controles');
        }

        // [Prêt de matériel] Un matériel prêté n'est pas contrôlable.
        $equipement = $this->equipementManager()->findId($equipement_id);
        if ($equipement && (int) ($equipement['statut'] ?? 0) === EquipementStatut::PRETE->value) {
            $this->session->getFlashBag()->add('error', sprintf(
                '%s est prêté : il ne peut être contrôlé qu\'après son retour.',
                $equipement['reference'] ?? 'Cet équipement'
            ));
            return $this->redirectTo("/admin/controles/edit/$controle_id");
        }

        $ligneManager = $this->controleLigneManager();
        $ligne_id = $ligneManager->save([
            'controle_id' => $controle_id,
            'equipement_id' => $equipement_id,
            'remarque' => null,
            'date_controle' => null,
            'statut' => 'a_controler'
        ]);

        // Ouvrir directement la fiche contrôle de l'équipement ajouté
        // (saisie du statut / remarque / date). Repli sur la page du
        // contrôle si l'ID de la ligne n'a pas pu être récupéré.
        if ($ligne_id) {
            return $this->redirectTo("/admin/controles/update-ligne/$ligne_id");
        }

        return $this->redirectTo("/admin/controles/edit/$controle_id");
    }

    public function updateLigne(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $ligne_id = $this->getValidId($request);
        if ($ligne_id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return $this->redirectTo('/admin/controles');
        }

        $ligneManager = $this->controleLigneManager();
        $ligne = $ligneManager->findId($ligne_id);

        if (!$ligne) {
            return $this->redirectTo('/admin/controles');
        }

        $controleManager = $this->controleManager();
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

        $retourScanner = $request->query->get('retour') === 'scanner';

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant mise à jour de la ligne
            $this->validateCsrf($request);

            // [SÉCURITÉ] Whitelist stricte du statut (ENUM MySQL).
            // Empêche un POST forgé de pousser une valeur arbitraire qui
            // serait silencieusement tronquée par MySQL (état incohérent).
            $statutRaw = $request->request->get('statut');
            $statutEnum = ControleLigneStatut::tryFrom((string) $statutRaw);
            if ($statutEnum === null) {
                error_log(sprintf(
                    '[ControleController] Invalid statut submitted for ligne id=%s: %s',
                    $ligne_id,
                    substr((string) $statutRaw, 0, 50)
                ));
                $this->session->getFlashBag()->add('error', 'Statut invalide.');
                return $this->redirectTo("/admin/controles/edit/{$controle['id']}");
            }
            
            // [SÉCURITÉ #53] Troncature défensive (remarque : TEXT, borne à 2000).
            $remarqueRaw = $request->request->get('remarque');
            $ligne['remarque'] = is_string($remarqueRaw)
            ? mb_substr($remarqueRaw, 0, 2000)
            : null;
            $ligne['date_controle'] = $request->request->get('date_controle');
            $ligne['statut'] = $statutEnum->value;

            $equipementManager = $this->equipementManager();
            $equipement = $equipementManager->findId($ligne['equipement_id']);

            // [MÉTIER 2026-10-21] Remplacement de l'élément textile : pris
            // en compte seulement pour un équipement « textile
            // remplaçable » en maintenance, contrôlé OK.
            $ligne['textile_remplace'] = (
                $equipement
                && self::textileRemplacementPossible($equipement)
                && $statutEnum === ControleLigneStatut::CONTROLE_OK
                && $request->request->has('textile_remplace')
            ) ? 1 : 0;

            $ligneManager->save($ligne);

            // ✅ Mettre à jour le statut de l'équipement : "en contrôle"
            if ($equipement) {
                $equipement['controle_en_cours'] = 1;
                $equipementManager->save($equipement);
            }

            // [2026-10-20] Fiche ouverte depuis le scanner → on y revient
            // pour enchaîner les scans.
            if ($retourScanner) {
                $this->session->getFlashBag()->add('success', sprintf(
                    'Contrôle de %s enregistré.',
                    $equipement['reference'] ?? 'l\'équipement'
                ));
                return $this->redirectTo('/controles/scanner');
            }

            return $this->redirectTo("/admin/controles/edit/{$controle['id']}");
        }

        if (is_null($ligne['date_controle'])) {
            $ligne['date_controle'] = date('Y-m-d H:i:s');
        }

        $equipementManager = $this->equipementManager();
        $equipement = $equipementManager->findId($ligne['equipement_id']);
        if ($equipement) {
            $ligne['reference'] = $equipement['reference'] ?? '';
            $ligne['libelle'] = $equipement['libelle'] ?? '';
            $ligne['photo'] = $equipement['photo'] ?? null;
        }

        return $this->render('controle_ligne_form.twig', [
            'ligne' => $ligne,
            'retour_scanner' => $retourScanner,
            'textile_remplacement_possible' => $equipement && self::textileRemplacementPossible($equipement),
        ]);
    }

    /**
     * [MÉTIER 2026-10-21] Le contrôleur peut signaler le remplacement de
     * l'élément textile d'un équipement « textile remplaçable » passé en
     * maintenance.
     *
     * @param array<string, mixed> $equipement
     */
    public static function textileRemplacementPossible(array $equipement): bool
    {
        return (int) ($equipement['textile_remplacable'] ?? 0) === 1
            && (int) ($equipement['statut'] ?? -1) === EquipementStatut::EN_MAINTENANCE->value;
    }

    /**
     * [MÉTIER 2026-10-21] Nouvelle date limite après remplacement du
     * textile : date du contrôle + 10 ans, fin de mois (null si la ligne
     * ne valide pas un remplacement).
     *
     * @param array<string, mixed> $ligne
     * @param array<string, mixed> $equipement État AVANT la clôture.
     */
    public static function dateLimiteApresRemplacementTextile(array $ligne, array $equipement): ?string
    {
        if ((int) ($ligne['textile_remplace'] ?? 0) !== 1
            || ($ligne['statut'] ?? null) !== ControleLigneStatut::CONTROLE_OK->value
            || !self::textileRemplacementPossible($equipement)
        ) {
            return null;
        }
        $date = trim((string) ($ligne['date_controle'] ?? ''));
        return DureeDeVie::finDeVie($date !== '' ? $date : date('Y-m-d'));
    }

    public function cloturer(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        // [SÉCURITÉ] Vérification CSRF (route POST-only ; action critique :
        // chiffre les remarques et met à jour le statut des équipements)
        $this->validateCsrf($request);

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return $this->redirectTo('/admin/controles');
        }

        $controleManager = $this->controleManager();
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

        $ligneManager = $this->controleLigneManager();
        $lignes = $ligneManager->findByControle($id);

        // Vérifier si des équipements sont encore "à contrôler"
        foreach ($lignes as $ligne) {
            if ($ligne['statut'] === 'a_controler') {
                $this->session->getFlashBag()->add('error', 'Impossible de clôturer : des équipements sont encore marqués "À contrôler".');
                return $this->redirectTo("/admin/controles/edit/$id");
            }
        }

        // ==================================================================
        // [SÉCURITÉ #52] Chiffrement idempotent des remarques.
        //
        // Depuis le fix #52, edit() chiffre hash_remarques dès l'écriture.
        // Ici, on ne chiffre QUE ce qui ne l'est pas encore (legacy en
        // clair créé avant le déploiement, ou lignes de remarque dont
        // l'écriture passe par updateLigne()).
        //
        // isEncrypted() utilise decryptRemarque() qui retourne null si la
        // valeur n'est pas un ciphertext valide → détection fiable sans
        // marqueur dédié.
        // ==================================================================
        
        // --- Remarque générale du contrôle ---
        $remarqueGenerale = $controle['hash_remarques'] ?? null;
        if ($remarqueGenerale !== null && $remarqueGenerale !== '') {
            if (!$this->isEncrypted($remarqueGenerale)) {
                // Legacy : chiffrement à la clôture (pré-fix #52).
                $hashGlobal = $this->encryptRemarque($remarqueGenerale);
            } else {
                // Déjà chiffré par edit() : on conserve la valeur telle quelle.
                $hashGlobal = $remarqueGenerale;
            }
        } else {
            $hashGlobal = null;
        }
        
        // --- Remarques des lignes ---
        foreach ($lignes as $ligne) {
            if (!is_null($ligne['remarque']) && $ligne['remarque'] !== '') {
                if (!$this->isEncrypted($ligne['remarque'])) {
                    $ligne['remarque'] = $this->encryptRemarque($ligne['remarque']);
                    $ligneManager->save($ligne);
                }
                // Si déjà chiffré (cas rare mais possible via updateLigne()
                // post-fix), on ne re-save() pas : iso-comportement observable.
            }
        }

        // Mise à jour du contrôle
        $controle['statut'] = 'cloture';
        $controle['date_fin'] = date('Y-m-d H:i:s');
        $controle['hash_remarques'] = $hashGlobal;
        $controleManager->save($controle);

        // ✅ Mettre à jour le statut des équipements
        // [SÉCURITÉ] Validation du statut via l'enum (cf. commit 2).
        // Évite qu'une valeur BDD hors enum (héritage, corruption, futur
        // ajout d'un statut non géré ici) ne laisse l'équipement dans un
        // état incohérent sans trace.
        $equipementManager = $this->equipementManager();
        foreach ($lignes as $ligne) {
            $equipement = $equipementManager->findId($ligne['equipement_id']);
            if (!$equipement) {
                continue;
            }
            
            $statutLigne = ControleLigneStatut::tryFrom((string) $ligne['statut']);
            
            if ($statutLigne === null) {
                // État en BDD hors enum : on log, on libère l'équipement
                // (controle_en_cours = 0) mais on ne touche pas à son statut.
                error_log(sprintf(
                    '[ControleController] Unexpected statut in cloturer() '
                    . 'for ligne id=%s, equipement id=%s: %s',
                    $ligne['id'],
                    $ligne['equipement_id'],
                    $ligne['statut']
                ));
                $equipement['controle_en_cours'] = 0;
                $equipementManager->save($equipement);
                continue;
            }
            
            // [MÉTIER 2026-10-21] Textile remplacé (équipement en
            // maintenance, contrôle OK) : la date limite repart de la
            // date du contrôle + 10 ans. Calculé AVANT le changement de
            // statut ci-dessous.
            $nouvelleLimite = self::dateLimiteApresRemplacementTextile($ligne, $equipement);
            if ($nouvelleLimite !== null) {
                $equipement['date_fin_utilisation'] = $nouvelleLimite;
            }

            match ($statutLigne) {
            ControleLigneStatut::HORS_SERVICE => $equipement['statut'] = EquipementStatut::HORS_SERVICE->value,
            ControleLigneStatut::CONTROLE_KO  => $equipement['statut'] = EquipementStatut::EN_MAINTENANCE->value,
            ControleLigneStatut::CONTROLE_OK  => $equipement['statut'] = EquipementStatut::DISPONIBLE->value,
            // [Prêt de matériel — migration 016] Matériel introuvable lors du contrôle.
            ControleLigneStatut::EGARE        => $equipement['statut'] = EquipementStatut::EGARE->value,
            ControleLigneStatut::A_CONTROLER  => error_log(sprintf(
                    '[ControleController] A_CONTROLER leaked through '
                    . 'cloturer() for ligne id=%s',
                    $ligne['id']
                )),
            };
            
            // [migration 017] Date de déclaration « Égaré » (délai avant
            // suppression) ; effacée quand l'équipement quitte ce statut.
            if ($statutLigne === ControleLigneStatut::EGARE) {
                $dateLigne = substr(trim((string) ($ligne['date_controle'] ?? '')), 0, 10);
                $equipement['date_egare'] = \Epiclub\Domain\PretRegles::dateValide($dateLigne) ? $dateLigne : date('Y-m-d');
            } elseif ($statutLigne !== ControleLigneStatut::A_CONTROLER) {
                $equipement['date_egare'] = null;
            }

            $equipement['controle_en_cours'] = 0;
            $equipementManager->save($equipement);
        }

        // Nettoyage de la session
        if ($user['controle_en_cours_id'] == $id) {
            $user['controle_en_cours_id'] = null;
            $utilisateurManager = $this->utilisateurManager();
            $utilisateurManager->save($user);
            $this->session->set('user', $user);
        }

        $this->session->getFlashBag()->add('success', 'Contrôle clôturé avec succès (remarques chiffrées).');
        return $this->redirectTo('/admin/controles');
    }

    /**
    * Supprime un contrôle non clôturé.
    * Règles :
    *   - ROLE_CONTROLLEUR requis
    *   - Seuls le contrôleur propriétaire ou un admin peuvent supprimer
    *   - Refusé si le contrôle est clôturé (préserve l'historique)
    *   - Remet controle_en_cours = 0 sur les équipements liés
    *   - Les controle_ligne sont supprimées par ON DELETE CASCADE
    */
    public function delete(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        // [SÉCURITÉ] Vérification CSRF (route POST-only)
        $this->validateCsrf($request);

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return $this->redirectTo('/admin/controles');
        }

        $controleManager = $this->controleManager();
        $controle = $controleManager->findId($id);

        if (!$controle) {
            $this->session->getFlashBag()->add('error', 'Contrôle non trouvé.');
            return $this->redirectTo('/admin/controles');
        }

        $user = $this->session->get('user');
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isOwner = $controle['controleur_id'] == $user['id'];

        // [SÉCURITÉ] Seuls le propriétaire ou un admin peuvent supprimer
        if (!$isOwner && !$isAdmin) {
            $this->session->getFlashBag()->add(
                'error',
                "Vous n'avez pas les droits pour supprimer ce contrôle."
            );
            return $this->redirectTo('/admin/controles');
        }

        // [MÉTIER] Refuser la suppression d'un contrôle clôturé
        if ($controle['statut'] === 'cloture') {
            $this->session->getFlashBag()->add(
                'error',
                "Impossible de supprimer un contrôle clôturé (l'historique doit être conservé)."
            );
            return $this->redirectTo("/admin/controles/edit/{$id}");
        }

        try {
            // [ROBUSTESSE] Remettre controle_en_cours = 0 sur les équipements liés
            $controleLigneManager = $this->controleLigneManager();
            $equipementManager = $this->equipementManager();
            $lignes = $controleLigneManager->findByControle($id);

            foreach ($lignes as $ligne) {
                $equipement = $equipementManager->findId($ligne['equipement_id']);
                if ($equipement && !empty($equipement['controle_en_cours'])) {
                    $equipement['controle_en_cours'] = 0;
                    $equipementManager->save($equipement);
                }
            }

            // Retirer le contrôle en cours de l'utilisateur s'il y en a un
            if (!empty($user['controle_en_cours_id']) && $user['controle_en_cours_id'] == $id) {
                $user['controle_en_cours_id'] = null;
                $utilisateurManager = $this->utilisateurManager();
                $utilisateurManager->save($user);
                $this->session->set('user', $user);
            }

            // [SÉCURITÉ] La FK ON DELETE CASCADE supprime automatiquement
            // les controle_ligne associées.
            $controleManager->delete($id);

            $this->session->getFlashBag()->add('success', "Le contrôle a été supprimé.");
            return $this->redirectTo('/admin/controles');
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[ControleController] Delete failed for controle id=%s: %s in %s:%d',
                $id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $this->session->getFlashBag()->add(
                'error',
                'Une erreur est survenue lors de la suppression. Merci de réessayer.'
            );
            return $this->redirectTo("/admin/controles/edit/{$id}");
        }
    }
}