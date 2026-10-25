<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\AcquisitionValidator;
use Epiclub\Domain\CorrectionAcquisition;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Domain\AcquisitionValidatorInterface;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\Pagination;
use Epiclub\Engine\FactureUploader; 
use Epiclub\Engine\HandlerResult;
use Epiclub\Engine\FactureUploaderInterface;
use Epiclub\Exception\DuplicateReferenceException;
use Epiclub\Exception\FactureUploadException;
use Epiclub\Exception\NotFoundException;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AcquisitionController extends AbstractController
{
    /**
    * [REFACTOR VAGUE 8] Retourne AcquisitionValidatorInterface (au lieu
    * de la classe concrète) pour permettre la surcharge/mock dans les tests.
    */
    protected function validator(): AcquisitionValidatorInterface
    {
        return new AcquisitionValidator(
            $this->acquisitionLigneManager(),
            $this->acquisitionManager(),
            $this->acquisitionProcess(),
        );
    }
    
    protected function acquisitionManager(): AcquisitionManager
    {
        return new AcquisitionManager();
    }
    
    protected function acquisitionLigneManager(): AcquisitionLigneManager
    {
        return new AcquisitionLigneManager();
    }
    
    protected function fournisseurManager(): FournisseurManager
    {
        return new FournisseurManager();
    }
    
    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }
    
    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }
    
    protected function referenceGenerator(): ReferenceGenerator
    {
        return new ReferenceGenerator();
    }

    protected function correctionAcquisition(): CorrectionAcquisition
    {
        return new CorrectionAcquisition($this->acquisitionManager(), $this->equipementManager());
    }

    /**
     * [Référencement — 2026-10-20] Proposition de référence pour une
     * nouvelle ligne d'acquisition, dès que la catégorie est choisie
     * (appel AJAX du formulaire). Format du modèle global, n° de suivi
     * suivant (équipements existants + lignes en attente).
     */
    public function suggestionReference(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $libelle = trim((string) $request->query->get('categorie', ''));
        if ($libelle === '' || mb_strlen($libelle) > 64) {
            return new JsonResponse(['reference' => null]);
        }

        $annee = (int) date('Y');
        $acquisitionId = (int) $request->query->get('acquisition', 0);
        if ($acquisitionId > 0) {
            $acquisition = $this->acquisitionManager()->findId($acquisitionId);
            if (!empty($acquisition['facture_date'])) {
                $annee = (int) date('Y', strtotime($acquisition['facture_date']));
            }
        }

        try {
            $reference = $this->referenceGenerator()->suggest($libelle, $annee);
        } catch (\Throwable $e) {
            error_log('[AcquisitionController] suggestionReference: ' . $e->getMessage());
            $reference = null;
        }

        return new JsonResponse(['reference' => $reference]);
    }

    /**
     * [Référencement — 2026-10-20] Une référence de ligne conforme au
     * modèle sert de n° de départ des équipements (cf.
     * AcquisitionProcess) ; une référence libre devient la référence
     * forcée des équipements (suffixée -1..-n pour plusieurs unités).
     */
    protected function referenceForceePour(string $reference, int $categorieId, ?string $factureDate): ?string
    {
        $annee = !empty($factureDate) ? (int) date('Y', strtotime($factureDate)) : (int) date('Y');
        try {
            $conforme = $this->referenceGenerator()->compteurSiConforme($reference, $categorieId, $annee) !== null;
        } catch (\Throwable $e) {
            error_log('[AcquisitionController] compteurSiConforme: ' . $e->getMessage());
            $conforme = false;
        }
        return $conforme ? null : $reference;
    }

    protected function acquisitionProcess(): AcquisitionProcess
    {
        return new AcquisitionProcess();
    }

    /**
     * Construit une RedirectResponse à partir du struct retourné par
     * AcquisitionValidator::performValidation(), et stocke le flash.
     *
     * @param array{route:string,type:'success'|'error',message:string,critical:bool} $result
     */
    private function redirectFromValidationResult(array $result): Response
    {
        $this->session->getFlashBag()->add($result['type'], $result['message']);
        return $this->redirectTo($result['route']);
    }

    /**
     * [REFACTOR #34] Façade respectant le contrat de l'issue :
     * performValidation(array $acquisition): ?Response
     *
     * Délègue au validator, puis convertit le struct en RedirectResponse
     * (avec flash). Centralise la construction des routes de redirection.
     *
     * @param array $acquisition Acquisition complète (avec 'id').
     */
    private function performValidation(array $acquisition): Response
    {
        return $this->redirectFromValidationResult(
            $this->validator()->performValidation(
                $acquisition,
                successRoute: "/admin/acquisitions/acquisition-{$acquisition['id']}",
                failureRoute: "/admin/acquisitions/acquisition_modification-{$acquisition['id']}",
            )
        );
    }

    /**
     * Remap les clés d'erreur de validateLigne() (reference, designation,
     * categorie_libelle, nombre) vers les clés attendues par
     * acquisition_form.twig (ligne_reference, ligne_designation, ...).
     *
     * @param array<string,string> $errors
     * @return array<string,string>
     */
    private function prefixLigneErrors(array $errors): array
    {
        $map = [
            'reference'         => 'ligne_reference',
            'designation'       => 'ligne_designation',
            'categorie_libelle' => 'ligne_categorie',   // ← template attend ligne_categorie
            'nombre'            => 'ligne_nombre',
        ];
        $out = [];
        foreach ($errors as $field => $msg) {
            $out[$map[$field] ?? ('ligne_' . $field)] = $msg;
        }
        return $out;
    }

    public function list(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        // Toutes les acquisitions (brouillons + validées).
        // Les brouillons ne sont pas cachés : ils sont accessibles pour
        // reprendre la saisie ou valider.
        $acquisitions = $this->acquisitionManager()->findAll();

        // Paramètres de filtre / tri, communs aux URLs de pagination et au formulaire
        $orderBy = (string) $request->query->get('order_by', 'id');
        if (!in_array($orderBy, self::LIST_ORDER_BY, true)) {
            $orderBy = 'id';
        }
        $orderDir = $request->query->get('order_dir') === 'desc' ? 'desc' : 'asc';
        $statut = $request->query->get('statut');
        $fournisseur = $request->query->get('fournisseur');
        $params = [
            'fournisseur' => is_string($fournisseur) && ctype_digit($fournisseur) ? $fournisseur : null,
            'statut' => in_array($statut, ['validee', 'en_attente'], true) ? $statut : null,
            'date_du' => self::dateFiltre($request, 'date_du'),
            'date_au' => self::dateFiltre($request, 'date_au'),
            'q' => self::recherche($request),
            'order_by' => $orderBy,
            'order_dir' => $orderDir,
        ];

        $acquisitions = self::filtrerEtTrier($acquisitions, $params);

        // Pagination
        $page = max(1, (int) $request->query->get('page', 1));
        // [2026-10-25] 10, 25, 50 ou 100 (cf. Pagination::limite)
        $limit = Pagination::limite($request->query->get('limit'));
        $params['limit'] = $limit;

        $total = count($acquisitions);
        $totalPages = max(1, (int) ceil($total / $limit));
        $page = min($page, $totalPages);
        $acquisitions = array_slice($acquisitions, ($page - 1) * $limit, $limit);

        $baseParams = array_filter($params, fn($v) => $v !== null && $v !== '');
        $paginationUrls = [
            'first' => '?' . http_build_query(array_merge($baseParams, ['page' => 1])),
            'previous' => '?' . http_build_query(array_merge($baseParams, ['page' => max(1, $page - 1)])),
            'next' => '?' . http_build_query(array_merge($baseParams, ['page' => min($totalPages, $page + 1)])),
            'last' => '?' . http_build_query(array_merge($baseParams, ['page' => $totalPages])),
        ];

        return $this->render('acquisition_list.twig', [
            'acquisitions' => $acquisitions,
            'fournisseurs' => $this->fournisseurManager()->findAll(),
            'filtres' => $params + ['page' => $page],
            'filtre_actif' => array_filter(array_intersect_key($params, array_flip(['fournisseur', 'statut', 'date_du', 'date_au', 'q']))) !== [],
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

    /** Clés de tri proposées dans la liste des acquisitions. */
    public const LIST_ORDER_BY = ['id', 'facture_reference', 'facture_date', 'fournisseur'];

    /**
     * Filtre et trie les acquisitions de la liste (fournisseur, statut,
     * période de facture, recherche sur la référence facture).
     *
     * @param list<array<string, mixed>> $acquisitions
     * @param array<string, mixed>       $params Paramètres déjà validés par list()
     * @return list<array<string, mixed>>
     */
    public static function filtrerEtTrier(array $acquisitions, array $params): array
    {
        if (!empty($params['fournisseur'])) {
            $acquisitions = array_filter($acquisitions, fn($a) =>
                isset($a['fournisseur_id']) && (string) $a['fournisseur_id'] === (string) $params['fournisseur']);
        }
        if (($params['statut'] ?? null) !== null) {
            $validee = $params['statut'] === 'validee';
            $acquisitions = array_filter($acquisitions, fn($a) => (bool) ($a['est_validee'] ?? false) === $validee);
        }
        if (!empty($params['date_du'])) {
            $acquisitions = array_filter($acquisitions, fn($a) =>
                !empty($a['facture_date']) && substr((string) $a['facture_date'], 0, 10) >= $params['date_du']);
        }
        if (!empty($params['date_au'])) {
            $acquisitions = array_filter($acquisitions, fn($a) =>
                !empty($a['facture_date']) && substr((string) $a['facture_date'], 0, 10) <= $params['date_au']);
        }
        if (!empty($params['q'])) {
            $aiguille = mb_strtolower((string) $params['q']);
            $acquisitions = array_filter($acquisitions, fn($a) =>
                str_contains(mb_strtolower((string) ($a['facture_reference'] ?? '')), $aiguille));
        }

        // --- Tri --- (à valeur égale, l'ID départage pour un ordre stable)
        $cle = match ($params['order_by'] ?? 'id') {
            'facture_reference' => fn($a) => mb_strtolower((string) ($a['facture_reference'] ?? '')),
            'facture_date' => fn($a) => (string) ($a['facture_date'] ?? ''),
            'fournisseur' => fn($a) => mb_strtolower((string) ($a['fournisseur']['nom'] ?? '')),
            default => fn($a) => (int) ($a['id'] ?? 0),
        };
        $sens = ($params['order_dir'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($acquisitions, fn($a, $b) =>
            $sens * (($cle($a) <=> $cle($b)) ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0))));

        return $acquisitions;
    }

    /** Texte recherché (« q »), nettoyé ; null si vide. */
    private static function recherche(Request $request): ?string
    {
        $valeur = $request->query->get('q');
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim(mb_substr($valeur, 0, 100));
        return $valeur === '' ? null : $valeur;
    }

    /** Date de filtre (Y-m-d) valide, sinon null. */
    private static function dateFiltre(Request $request, string $nom): ?string
    {
        $valeur = $request->query->get($nom);
        if (!is_string($valeur)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        return $date !== false && $date->format('Y-m-d') === $valeur ? $valeur : null;
    }

    public function create(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $fournisseurManager = $this->fournisseurManager();
        $categorieManager = $this->categorieManager();
        $acquisition = [];
        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            // [ROBUSTESSE] Détection d'un POST tronqué par post_max_size.
            // Si le fichier dépasse post_max_size, PHP vide $_POST et $_FILES
            // AVANT d'appeler le contrôleur → le CSRF semble "manquant" alors
            // que la vraie cause est un fichier trop gros. On traite ce cas
            // en priorité pour afficher un message utile.
            if ($this->isPostTruncated($request)) {
                $form_errors['facture_document'] = sprintf(
                    'Le fichier est trop volumineux pour être traité par le serveur (limite PHP : %s). '
                    . 'Réduisez la taille du fichier ou augmentez post_max_size.',
                    ini_get('post_max_size')
                );
            } else {
                // [SÉCURITÉ] Vérification CSRF avant tout traitement (y compris upload facture).
                // Reste dans create() (hors dispatch) car validateCsrf() doit conserver
                // son throw : il protège toutes les actions d'un seul coup.
                $this->validateCsrf($request);
                
                $action = $request->request->get('action');
                
                // [REFACTOR VAGUE 7] Dispatch vers un handler qui retourne
                // un HandlerResult (plus de mutation par référence).
                $result = match ($action) {
                    'create' => $this->handleCreateAction($request, $acquisition),
                    default  => null,
                };
                
                if ($result !== null) {
                    if ($result->isRedirect()) {
                        return $result->response;
                    }
                    // Rendu du formulaire avec la saisie et les erreurs remontées
                    $acquisition = $result->acquisition;
                    $form_errors = $result->formErrors;
                }
            }
        }
        
        return $this->render('acquisition_form.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll(),
            'categories' => $categorieManager->findAll(),
            'form_errors' => $form_errors,
        ]);
    }

    /**
    * [REFACTOR VAGUE 4 → 7] Handler de l'action 'create'.
    *
    * Extrait de create() pour isoler la création d'une acquisition
    * (whitelist, unicité référence, upload facture, process métier).
    *
    * [VAGUE 7] Retourne un HandlerResult au lieu de muter par référence :
    *   - response    : RedirectResponse en cas de succès, null sinon
    *   - acquisition : champs whitelistés + 'saisie_par' + 'facture_document'
    *                   (remonté même en cas d'erreur pour réaffichage)
    *   - formErrors  : erreurs indexées par champ
    *
    * @param array $acquisition  Acquisition initiale (généralement vide).
    */
    protected function handleCreateAction(
        Request $request,
        array $acquisition,
    ): HandlerResult {
        // [SÉCURITÉ] Whitelist des champs — empêche l'injection de clés
        // arbitraires (id, est_validee, saisie_par, ...) via POST forgé.
        $acquisition = [
            'facture_reference' => trim((string) $request->request->get('facture_reference', '')),
            'facture_date'      => $request->request->get('facture_date'),
            'fournisseur_nom'   => trim((string) $request->request->get('fournisseur_nom', '')),
        ];
        $acquisition['saisie_par'] = $this->session->get('user')['id'];
        $acquisition['facture_document'] = null;
        
        $formErrors = [];
        
        // [ROBUSTESSE] Vérifier l'unicité de la référence AVANT l'insert
        $factureReference = $acquisition['facture_reference'];
        if ($factureReference === '') {
            $formErrors['facture_reference'] = 'La référence de facture est obligatoire.';
        } else {
            $acquisitionManager = $this->acquisitionManager();
            if ($acquisitionManager->findOneByCriteria(['facture_reference' => $factureReference])) {
                $formErrors['facture_reference'] = 'Cette référence de facture existe déjà. Merci d\'en choisir une autre.';
            }
        }
        
        // Téléchargement de la facture (seulement si pas d'erreur bloquante)
        if (empty($formErrors)) {
            try {
                $factureDocument = $this->factureUploader()
                ->upload($request->files->get('facture_document'));
                if ($factureDocument !== null) {
                    $acquisition['facture_document'] = $factureDocument;
                }
            } catch (FactureUploadException $e) {
                $formErrors['facture_document'] = $e->getMessage();
            }
        }
        
        // [ROBUSTESSE] Ne lancer le process métier que si pas d'erreur.
        // [VAGUE 7] On remonte $acquisition modifié (whitelist + facture_document
        // éventuellement uploadé) pour que le template réaffiche la saisie.
        if (!empty($formErrors)) {
            return new HandlerResult(null, $acquisition, $formErrors);
        }
        
        $acquisitionProcess = $this->acquisitionProcess();
        try {
            if ($id = $acquisitionProcess->acquisition_process($acquisition)) {
                $acquisition['id'] = $id;
                $this->session->getFlashBag()->add(
                    'success',
                    '✅ Acquisition créée avec succès. Vous pouvez maintenant ajouter des lignes.'
                );
                return new HandlerResult(
                    $this->redirectTo("/admin/acquisitions/acquisition_modification-$id")
                );
            }
            $formErrors['general'] = 'Erreur lors de la création de l\'acquisition.';
            return new HandlerResult(null, $acquisition, $formErrors);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AcquisitionController] Create failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $formErrors['general'] = 'Une erreur est survenue lors de la création. Merci de réessayer.';
            return new HandlerResult(null, $acquisition, $formErrors);
        }
    }
    
    public function update(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $acquisitionManager = $this->acquisitionManager();
        $acquisitionLigneManager = $this->acquisitionLigneManager();
        $fournisseurManager = $this->fournisseurManager();
        $categorieManager = $this->categorieManager();
        $equipementManager = $this->equipementManager();
        
        // [ROBUSTESSE] Cast explicite de l'id
        $id = (int) $request->get('id');
        if ($id <= 0) {
            $this->session->getFlashBag()->add('error', 'Acquisition invalide.');
            return $this->redirectTo('/admin/acquisitions');
        }
        
        $acquisition = $acquisitionManager->findId($id);
        if (!$acquisition) {
            $this->session->getFlashBag()->add('error', 'Acquisition non trouvée.');
            return $this->redirectTo('/admin/acquisitions');
        }
        
        $acquisition['lignes'] = $acquisitionLigneManager->findByAcquisition($acquisition['id']);
        $form_errors = [];
        $ligneData = [];
        
        if ($request->getMethod() === 'POST') {
            // [ROBUSTESSE] Détection d'un POST tronqué par post_max_size (cf. create()).
            if ($this->isPostTruncated($request)) {
                $form_errors['facture_document'] = sprintf(
                    'Le fichier est trop volumineux pour être traité par le serveur (limite PHP : %s). '
                    . 'Réduisez la taille du fichier ou augmentez post_max_size.',
                    ini_get('post_max_size')
                );
            } else {
                // [SÉCURITÉ] Vérification CSRF avant tout traitement (y compris upload facture).
                // Reste dans update() (hors dispatch) car validateCsrf() doit conserver
                // son throw : il protège toutes les actions d'un seul coup.
                $this->validateCsrf($request);
                
                $action = $request->request->get('action');
                
                // [REFACTOR VAGUE 3] Dispatch par match() vers des handlers dédiés.
                // Convention actuelle : un handler retourne ?Response :
                //   - Response : redirection immédiate (succès ou refus métier)
                //   - null     : on continue vers le rendu du formulaire (erreurs)
                // [REFACTOR VAGUE 7] Dispatch vers des handlers qui retournent
                // un HandlerResult (plus de mutation par référence).
                // Cas spécial 'valider' : performValidation() retourne directement
                // une Response, on la traite hors du match DTO.
                if ($action === 'valider') {
                    return $this->performValidation($acquisition);
                }
                
                $result = match ($action) {
                    'update'    => $this->handleUpdateAction($request, $acquisition),
                    'add_ligne' => $this->handleAddLigneAction($request, $acquisition),
                    // [MÉTIER] Remplacement du SEUL document de facture :
                    // autorisé même sur une acquisition validée (ex.
                    // facture téléversée après un import Excel/CSV).
                    'update_facture' => $this->handleUpdateFactureAction($request, $acquisition),
                    default     => null,
                };
                
                if ($result !== null) {
                    if ($result->isRedirect()) {
                        return $result->response;
                    }
                    // Rendu du formulaire avec la saisie et les erreurs remontées
                    $acquisition = $result->acquisition;
                    $form_errors = $result->formErrors;
                    $ligneData = $result->ligneData;
                }
            }
        }
        
        $equipements = $equipementManager->findAll();
        
        return $this->render('acquisition_form.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll(),
            'categories' => $categorieManager->findAll(),
            'equipements' => $equipements,
            'form_errors' => $form_errors,
            'ligne_data' => $ligneData ?? [],
        ]);
    }

    /**
    * [REFACTOR VAGUE 3 → 7] Handler de l'action 'update'.
    *
    * Extrait de update() pour isoler la logique de mise à jour de l'acquisition
    * (fournisseur, référence facture, date, upload PDF).
    *
    * [VAGUE 7] Retourne un HandlerResult au lieu de muter par référence :
    *   - response    : RedirectResponse en cas de succès, null sinon
    *   - acquisition : remontée en cas d'erreur (avec fournisseur_id,
    *                   facture_reference, facture_date, facture_document
    *                   mis à jour pour réaffichage)
    *   - formErrors  : erreurs indexées par champ
    *
    * @param array $acquisition  Acquisition chargée (avec 'id', 'facture_document').
    */
    protected function handleUpdateAction(
        Request $request,
        array $acquisition,
    ): HandlerResult {
        $acquisitionManager = $this->acquisitionManager();
        $fournisseurManager = $this->fournisseurManager();
        
        $fournisseurNom = trim((string) $request->request->get('fournisseur_nom', ''));
        $factureReference = trim((string) $request->request->get('facture_reference', ''));
        $factureDate = $request->request->get('facture_date');
        
        $formErrors = [];
        
        // [ROBUSTESSE] Vérifier l'unicité de la référence AVANT l'update
        if ($factureReference === '') {
            $formErrors['facture_reference'] = 'La référence de facture est obligatoire.';
        } else {
            $existing = $acquisitionManager->findOneByCriteria(['facture_reference' => $factureReference]);
            if ($existing && (int) $existing['id'] !== (int) $acquisition['id']) {
                $formErrors['facture_reference'] = 'Cette référence de facture est déjà utilisée par une autre acquisition.';
            }
        }
        
        // Gestion du téléchargement du PDF (seulement si pas d'erreur bloquante)
        $uploadedFacture = $request->files->get('facture_document');
        if (empty($formErrors['facture_reference']) && $uploadedFacture !== null) {
            $uploader = $this->factureUploader();
            try {
                $newFacture = $uploader->upload($uploadedFacture);
                
                // Supprimer l'ancienne facture (best-effort)
                if (!empty($acquisition['facture_document'])) {
                    $uploader->delete($acquisition['facture_document']);
                }
                
                $acquisition['facture_document'] = $newFacture;
            } catch (FactureUploadException $e) {
                $formErrors['facture_document'] = $e->getMessage();
            }
        }
        
        // [ROBUSTESSE] Ne mettre à jour le fournisseur que si pas d'erreur.
        // [VAGUE 7] On remonte $acquisition modifié (facture_document
        // éventuellement mis à jour) pour que le template réaffiche la saisie.
        if (!empty($formErrors)) {
            return new HandlerResult(null, $acquisition, $formErrors);
        }
        
        $fournisseur = $fournisseurManager->findOneByCriteria(['nom' => $fournisseurNom]);
        if ($fournisseur) {
            $fournisseurId = $fournisseur['id'];
        } else {
            $fournisseurId = $fournisseurManager->save(['nom' => $fournisseurNom]);
        }
        
        $acquisition['fournisseur_id'] = $fournisseurId;
        $acquisition['facture_reference'] = $factureReference;
        $acquisition['facture_date'] = $factureDate;
        
        try {
            $acquisitionManager->save($acquisition);
            $this->session->getFlashBag()->add('success', '✅ Acquisition mise à jour avec succès.');
            return new HandlerResult(
                $this->redirectTo("/admin/acquisitions/acquisition_modification-{$acquisition['id']}")
            );
        } catch (\Throwable $e) {
            // [SÉCURITÉ] Log serveur, message générique au client
            error_log(sprintf(
                '[AcquisitionController] Update failed for acquisition id=%s: %s in %s:%d',
                $acquisition['id'],
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $formErrors['general'] = 'Une erreur est survenue lors de la mise à jour. Merci de réessayer.';
            return new HandlerResult(null, $acquisition, $formErrors);
        }
    }

    /**
    * [REFACTOR VAGUE 3 → 7] Handler de l'action 'add_ligne'.
    *
    * Extrait de update() pour isoler l'ajout d'une ligne d'acquisition
    * (whitelist, validation, catégorie, save).
    *
    * [VAGUE 7] Retourne un HandlerResult :
    *   - response    : RedirectResponse en cas de succès, null sinon
    *   - acquisition : non modifié (lecture seule de l'id)
    *   - formErrors  : erreurs indexées par champ
    *   - ligneData   : données de ligne saisies (pour réaffichage en cas d'erreur)
    *
    * @param array $acquisition  Acquisition chargée (avec 'id').
    */
    protected function handleAddLigneAction(
        Request $request,
        array $acquisition,
    ): HandlerResult {
        $acquisitionLigneManager = $this->acquisitionLigneManager();
        
        // [SÉCURITÉ] Whitelist des champs — empêche l'injection de clés
        // arbitraires (id, acquisition_id, equipements_generes) via POST forgé.
        $raw = $request->request->all('ligne');
        $raw = is_array($raw) ? $raw : [];
        
        $ligne = [
            'reference'         => trim((string) ($raw['reference'] ?? '')),
            'designation'       => trim((string) ($raw['designation'] ?? '')),
            'categorie_libelle' => trim((string) ($raw['categorie_libelle'] ?? '')),
            'nombre'            => (int) ($raw['nombre'] ?? 0),
            'regrouper_en_lot'  => isset($raw['regrouper_en_lot']) ? 1 : 0,
        ];
        $ligneData = $ligne;
        $formErrors = [];
        
        if ($ligne['reference'] === '') {
            $formErrors['ligne_reference'] = 'Veuillez remplir les champs de la ligne.';
            return new HandlerResult(null, $acquisition, $formErrors, $ligneData);
        }
        
        // Validation centralisée, puis remap des clés courtes
        // (reference, designation…) vers les clés attendues par
        // acquisition_form.twig (ligne_reference, ligne_designation…).
        $lineErrors = $this->validator()->validateLigne($ligne);
        $formErrors = array_merge(
            $formErrors,
            $this->prefixLigneErrors($lineErrors)
        );
        
        if (!empty($formErrors)) {
            return new HandlerResult(null, $acquisition, $formErrors, $ligneData);
        }
        
        try {
            $acquisitionProcess = $this->acquisitionProcess();
            $ligne['categorie_id'] = $acquisitionProcess->categorie_process($ligne);
            $ligne['reference_forcee'] = $this->referenceForceePour(
                $ligne['reference'],
                (int) $ligne['categorie_id'],
                $acquisition['facture_date'] ?? null
            );
            $ligne['acquisition_id'] = $acquisition['id'];
            $ligne['equipements_generes'] = 0;
            
            $acquisitionLigneManager->save($ligne);
            $this->session->getFlashBag()->add('success', '✅ Ligne ajoutée avec succès.');
            return new HandlerResult(
                $this->redirectTo("/admin/acquisitions/acquisition_modification-{$acquisition['id']}")
            );
        } catch (DuplicateReferenceException $e) {
            // [ROBUSTESSE] Race condition : la pré-vérification
            // findByReference() a passé, mais la contrainte UNIQUE
            // a rejeté l'INSERT. On affiche l'erreur sur le bon champ.
            $formErrors['ligne_reference'] = 'Cette référence existe déjà. Veuillez en saisir une autre.';
            return new HandlerResult(null, $acquisition, $formErrors, $ligneData);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AcquisitionController] Add ligne failed for acquisition id=%s: %s in %s:%d',
                $acquisition['id'],
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $formErrors['ligne_general'] = 'Une erreur est survenue lors de l\'ajout de la ligne.';
            return new HandlerResult(null, $acquisition, $formErrors, $ligneData);
        }
    }

    /**
     * [MÉTIER] Handler de l'action 'update_facture'.
     *
     * Remplace UNIQUEMENT le document de facture (mise à jour partielle,
     * filteredUpdate sans les autres champs). Autorisé même sur une
     * acquisition validée : cas d'usage principal = téléverser la
     * facture réelle après un import Excel/CSV (placeholder
     * « facture à importer » — AcquisitionManager::FACTURE_A_IMPORTER).
     *
     * Toutes les branches retournent une RedirectResponse (flash) : le
     * formulaire appelant vit sur la fiche acquisition, pas sur le
     * formulaire d'édition.
     *
     * @param array $acquisition Acquisition chargée (avec 'id', 'facture_document').
     */
    protected function handleUpdateFactureAction(
        Request $request,
        array $acquisition,
    ): HandlerResult {
        $id = (int) $acquisition['id'];
        $redirect = fn(): Response => $this->redirectTo("/admin/acquisitions/acquisition-{$id}");

        $fichier = $request->files->get('facture_document');
        if (!$fichier) {
            $this->session->getFlashBag()->add('error', 'Veuillez choisir un fichier de facture.');
            return new HandlerResult($redirect());
        }

        $uploader = $this->factureUploader();
        try {
            $newFacture = $uploader->upload($fichier);
            if ($newFacture === null) {
                $this->session->getFlashBag()->add('error', 'Veuillez choisir un fichier de facture.');
                return new HandlerResult($redirect());
            }

            // [ROBUSTESSE] Ne pas tenter de supprimer le placeholder :
            // ce n'est pas un fichier réel.
            if (!empty($acquisition['facture_document'])
                && $acquisition['facture_document'] !== AcquisitionManager::FACTURE_A_IMPORTER) {
                $uploader->delete($acquisition['facture_document']);
            }

            // Mise à jour partielle : SEUL facture_document est écrit.
            $this->acquisitionManager()->save([
                'id' => $id,
                'facture_document' => $newFacture,
            ]);

            $this->session->getFlashBag()->add('success', '✅ La facture a été téléversée.');
            return new HandlerResult($redirect());
        } catch (FactureUploadException $e) {
            $this->session->getFlashBag()->add('error', $e->getMessage());
            return new HandlerResult($redirect());
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AcquisitionController] Update facture failed for acquisition id=%s: %s in %s:%d',
                $id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $this->session->getFlashBag()->add(
                'error',
                'Une erreur est survenue lors du téléversement. Merci de réessayer.'
            );
            return new HandlerResult($redirect());
        }
    }

    public function delete(Request $request): Response
    {
        // [SÉCURITÉ] Seul un admin peut supprimer une acquisition
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        // [SÉCURITÉ] Vérification CSRF (avant toute suppression de fichier ou d'enregistrement).
        // Reste hors du handler pour conserver son throw protecteur.
        $this->validateCsrf($request);
        
        // [ROBUSTESSE] Cast explicite de l'id
        $id = (int) $request->get('id');
        if ($id <= 0) {
            $this->session->getFlashBag()->add('error', 'Acquisition invalide.');
            return $this->redirectTo('/admin/acquisitions');
        }
        
        $acquisitionManager = $this->acquisitionManager();
        $acquisition = $acquisitionManager->findId($id);
        if (!$acquisition) {
            $this->session->getFlashBag()->add('error', 'Acquisition non trouvée.');
            return $this->redirectTo('/admin/acquisitions');
        }
        
        // [REFACTOR VAGUE 7] Le handler retourne un HandlerResult.
        // Par contrat, response est TOUJOURS non-null : on retourne
        // directement la Response.
        $result = $this->handleDeleteAction($acquisition);
        return $result->response;
    }
    
    /**
    * [REFACTOR VAGUE 6 → 7] Handler de l'action 'delete'.
    *
    * Extrait de delete() pour symétrie avec handleCreateAction() /
    * handleUpdateAction() / handleAddLigneAction().
    *
    * [VAGUE 7] Retourne un HandlerResult :
    *   - response    : TOUJOURS non-null (RedirectResponse)
    *                   → pas de rendu de formulaire en cas d'erreur,
    *                     toutes les branches redirigent avec un flash.
    *   - acquisition : vide (non utilisé)
    *   - formErrors  : vide (non utilisé)
    *   - ligneData   : vide (non utilisé)
    *
    * @param array $acquisition  Acquisition chargée (avec 'id',
    *                            'est_validee', 'facture_document').
    */
    protected function handleDeleteAction(array $acquisition): HandlerResult
    {
        $id = (int) $acquisition['id'];
        
        // [MÉTIER] Seuls les brouillons peuvent être supprimés
        if ($acquisition['est_validee']) {
            $this->session->getFlashBag()->add(
                'error',
                "Impossible de supprimer une acquisition validée. Elle contient des équipements générés."
            );
            return new HandlerResult(
                $this->redirectTo("/admin/acquisitions/acquisition-{$id}")
            );
        }
        
        // [SÉCURITÉ] Refuser si une ligne a déjà généré des équipements
        $acquisitionLigneManager = $this->acquisitionLigneManager();
        $lignes = $acquisitionLigneManager->findByAcquisition($id);
        foreach ($lignes as $ligne) {
            if ($ligne['equipements_generes'] == 1) {
                $this->session->getFlashBag()->add(
                    'error',
                    "Impossible de supprimer cette acquisition : certaines lignes ont généré des équipements."
                );
                return new HandlerResult(
                    $this->redirectTo("/admin/acquisitions/acquisition_modification-{$id}")
                );
            }
        }
        
        try {
            // [SÉCURITÉ] Supprimer d'abord les lignes (pas de FK ON DELETE CASCADE déclarée)
            foreach ($lignes as $ligne) {
                $acquisitionLigneManager->delete($ligne['id']);
            }
            
            // Supprimer la facture associée si elle existe (best-effort)
            if (!empty($acquisition['facture_document'])) {
                $this->factureUploader()->delete($acquisition['facture_document']);
            }
            
            // Puis l'acquisition
            $acquisitionManager = $this->acquisitionManager();
            $acquisitionManager->delete($id);
            
            $this->session->getFlashBag()->add('success', "L'acquisition #{$id} a été supprimée.");
            return new HandlerResult($this->redirectTo('/admin/acquisitions'));
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AcquisitionController] Delete failed for acquisition id=%s: %s in %s:%d',
                $id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $this->session->getFlashBag()->add(
                'error',
                'Une erreur est survenue lors de la suppression. Merci de réessayer.'
            );
            return new HandlerResult(
                $this->redirectTo("/admin/acquisitions/acquisition-{$id}")
            );
        }
    }

    public function show(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $acquisitionManager = $this->acquisitionManager();
        $fournisseurManager = $this->fournisseurManager();
        $acquisitionLigneManager = $this->acquisitionLigneManager();

        $id = (int) $request->get('id');
        if ($id <= 0) {
            return $this->redirectTo('/admin/acquisitions');
        }

        $acquisition = $acquisitionManager->findId($id);
        if (!$acquisition) {
            return $this->redirectTo('/admin/acquisitions');
        }

        $acquisition['lignes'] = $acquisitionLigneManager->findByAcquisition($acquisition['id']);

        // [2026-10-24] Correction de la référence et de la date (super-admin) :
        // équipements dont les dates seront réalignées ou réinterrogées.
        $correction = $this->isGranted('ROLE_SUPER_ADMIN');

        return $this->render('acquisition_show.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll(),
            'correction_possible' => $correction,
            'equipements_dates' => $correction
                ? CorrectionAcquisition::classer(
                    $this->equipementManager()->findByAcquisition((int) $acquisition['id']),
                    (string) $acquisition['facture_date']
                )
                : [],
            'mot_confirmation_reference' => CorrectionAcquisition::MOT_CONFIRMATION_REFERENCE,
            'mot_confirmation_date' => CorrectionAcquisition::MOT_CONFIRMATION_DATE,
            'reference_facture_max' => CorrectionAcquisition::REFERENCE_MAX,
        ]);
    }

    // --------------------------------------------------------------
    // [2026-10-24] CORRECTION DE LA RÉFÉRENCE ET DE LA DATE (ROLE_SUPER_ADMIN),
    // depuis la fiche de l'acquisition (roue dentée). Garde-fous : unicité
    // de la référence vérifiée à la volée puis côté serveur, mots de
    // confirmation REFERENCE et DATE, dates manuelles des équipements
    // réinterrogées (cf. CorrectionAcquisition).
    // --------------------------------------------------------------

    /**
     * GET JSON /admin/acquisitions/reference-facture-disponible?id=&reference=
     */
    public function referenceFactureDisponible(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $reference = trim((string) $request->query->get('reference', ''));
        $id = (int) $request->query->get('id', 0);

        $erreur = CorrectionAcquisition::erreurReference($reference);
        if ($erreur !== null) {
            return new JsonResponse(['disponible' => false, 'message' => $erreur]);
        }
        $existante = $this->acquisitionManager()->findOneByCriteria(['facture_reference' => $reference]);
        if ($existante && (int) $existante['id'] === $id) {
            return new JsonResponse(['disponible' => false, 'message' => 'C\'est la référence actuelle.']);
        }
        return new JsonResponse([
            'disponible' => !$existante,
            'message' => $existante
                ? 'Cette référence de facture est déjà utilisée par une autre acquisition.'
                : 'Référence disponible.',
        ]);
    }

    /**
     * POST /admin/acquisitions/acquisition_corriger-{id}
     * Les contrôles faits dans le navigateur sont refaits ici ; en cas de
     * refus, retour à la fiche avec le motif.
     */
    public function corriger(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $this->validateCsrf($request);

        $id = (int) $request->get('id');
        $acquisition = $id > 0 ? $this->acquisitionManager()->findId($id) : null;
        if (!$acquisition) {
            $this->session->getFlashBag()->add('error', 'Acquisition non trouvée.');
            return $this->redirectTo('/admin/acquisitions');
        }
        $flash = $this->session->getFlashBag();
        $fiche = "/admin/acquisitions/acquisition-{$id}";

        $ancienneReference = (string) $acquisition['facture_reference'];
        $ancienneDate = substr((string) $acquisition['facture_date'], 0, 10);
        $reference = trim((string) $request->request->get('facture_reference', $ancienneReference));
        $date = trim((string) $request->request->get('facture_date', $ancienneDate));
        $referenceChangee = $reference !== $ancienneReference;
        $dateChangee = $date !== $ancienneDate;

        $erreur = null;
        $modifs = [];
        if (!$referenceChangee && !$dateChangee) {
            $flash->add('note', 'Aucune modification : la référence et la date sont inchangées.');
            return $this->redirectTo($fiche);
        }
        if ($referenceChangee) {
            $erreur = CorrectionAcquisition::erreurReference($reference);
            if ($erreur === null
                && trim((string) $request->request->get('confirmation_reference', '')) !== CorrectionAcquisition::MOT_CONFIRMATION_REFERENCE) {
                $erreur = sprintf('Pour changer la référence, saisissez le mot %s (en majuscules).', CorrectionAcquisition::MOT_CONFIRMATION_REFERENCE);
            }
            if ($erreur === null) {
                $existante = $this->acquisitionManager()->findOneByCriteria(['facture_reference' => $reference]);
                if ($existante && (int) $existante['id'] !== $id) {
                    $erreur = sprintf('La référence de facture « %s » est déjà utilisée par une autre acquisition.', $reference);
                }
            }
        }
        if ($erreur === null && $dateChangee) {
            if (!CorrectionAcquisition::dateValide($date)) {
                $erreur = 'Date de facture invalide.';
            } elseif (trim((string) $request->request->get('confirmation_date', '')) !== CorrectionAcquisition::MOT_CONFIRMATION_DATE) {
                $erreur = sprintf('Pour changer la date, saisissez le mot %s (en majuscules).', CorrectionAcquisition::MOT_CONFIRMATION_DATE);
            } else {
                $lignes = CorrectionAcquisition::classer($this->equipementManager()->findByAcquisition($id), $ancienneDate);
                $resultat = CorrectionAcquisition::nouvellesDates(
                    $lignes,
                    $date,
                    $request->request->all('date_mise_en_service'),
                    $request->request->all('date_fin_utilisation')
                );
                if (is_string($resultat)) {
                    $erreur = $resultat;
                } else {
                    $modifs = $resultat;
                }
            }
        }
        if ($erreur !== null) {
            $flash->add('error', $erreur);
            return $this->redirectTo($fiche);
        }

        try {
            $this->correctionAcquisition()->appliquer($id, $reference, $date, $modifs);
        } catch (\PDOException $e) {
            // Course sur la référence (contrainte UNIQUE) : rien n'a été écrit.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $flash->add('error', sprintf('La référence de facture « %s » vient d\'être utilisée par une autre acquisition : rien n\'a été modifié.', $reference));
            return $this->redirectTo($fiche);
        }

        $user = $this->session->get('user');
        error_log(sprintf(
            '[AcquisitionController] Acquisition id=%d corrigée : référence « %s » → « %s », date %s → %s, %d équipement(s) réalignés (utilisateur id=%s)',
            $id, $ancienneReference, $reference, $ancienneDate, $date, count($modifs), $user['id'] ?? '?'
        ));
        $details = [];
        if ($referenceChangee) {
            $details[] = sprintf('référence « %s » → « %s »', $ancienneReference, $reference);
        }
        if ($dateChangee) {
            $details[] = sprintf('date %s → %s', date('d/m/Y', (int) strtotime($ancienneDate)), date('d/m/Y', (int) strtotime($date)));
        }
        $flash->add('success', 'Acquisition modifiée : ' . implode(', ', $details)
            . ($modifs ? sprintf(' ; dates de %d équipement(s) mises à jour.', count($modifs)) : '.'));
        return $this->redirectTo($fiche);
    }

    public function valider(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        // [SÉCURITÉ] Vérification CSRF (avant génération des équipements)
        $this->validateCsrf($request);

        $id = (int) $request->get('id');
        $acquisitionManager = $this->acquisitionManager();
        $acquisition = $acquisitionManager->findId($id);

        if (!$acquisition) {
            $this->session->getFlashBag()->add('error', 'Acquisition non trouvée.');
            return $this->redirectTo('/admin/acquisitions');
        }

        if ($acquisition['est_validee']) {
            $this->session->getFlashBag()->add('error', 'Cette acquisition est déjà validée.');
            return $this->redirectTo("/admin/acquisitions/acquisition-{$id}");
        }

        // [REFACTOR #34] Bloc extrait vers AcquisitionValidator::performValidation()
        return $this->performValidation($acquisition);
    }

    public function serveFile(Request $request): BinaryFileResponse
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $path = (string) $request->attributes->get('path');

        // [SÉCURITÉ] Path traversal correctement bloqué via realpath()
        $uploadsDir = $this->getUploadsDir();
        $realBase = realpath($uploadsDir);
        $realPath = realpath($uploadsDir . ltrim($path, '/'));

        if ($realBase === false
            || $realPath === false
            || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)
        ) {
            throw new NotFoundException('Fichier non trouvé');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($realPath) ?: 'application/octet-stream';

        return new BinaryFileResponse($realPath, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($realPath) . '"',
        ]);
    }

    /**
     * [ROBUSTESSE] Centralise le chemin de base des uploads privés.
     * _storage/ est à la racine du projet, hors document_root.
     */
    private function getUploadsDir(): string
    {
        return dirname(__DIR__, 2) . '/_storage/uploads/';
    }
    
    /**
    * [REFACTOR VAGUE 8] Retourne FactureUploaderInterface (au lieu de
    * la classe concrète) pour permettre la surcharge/mock dans les tests.
    *
    * Le chemin est résolu à la construction (issue #40, D1-a) :
    * le service ne connaît pas la structure du projet.
    */
    protected function factureUploader(): FactureUploaderInterface
    {
        return new FactureUploader($this->getUploadsDir());
    }
}