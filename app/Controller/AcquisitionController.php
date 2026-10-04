<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\AcquisitionValidator;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\FactureUploader; 
use Epiclub\Engine\HandlerResult;
use Epiclub\Exception\DuplicateReferenceException;
use Epiclub\Exception\FactureUploadException;
use Epiclub\Exception\NotFoundException;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AcquisitionController extends AbstractController
{
    /**
     * Instancie le validator (issue #34).
     *
     * Pas de cache : le constructeur ne fait aucune I/O, l'instanciation
     * est triviale et évite tout état persistant entre appels.
     */
    protected function validator(): AcquisitionValidator
    {
        return new AcquisitionValidator(
            new AcquisitionLigneManager(),
            new AcquisitionManager(),
            new AcquisitionProcess(),
        );
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

        $acquisitionManager = new AcquisitionManager();
        $acquisitions = $acquisitionManager->findAll();

        // Toutes les acquisitions (brouillons + validées).
        // Les brouillons ne sont pas cachés : ils sont accessibles pour
        // reprendre la saisie ou valider.

        return $this->render('acquisition_list.twig', [
            'acquisitions' => $acquisitions,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $fournisseurManager = new FournisseurManager();
        $categorieManager = new CategorieManager();
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
            $acquisitionManager = new AcquisitionManager();
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
        
        $acquisitionProcess = new AcquisitionProcess();
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
        
        $acquisitionManager = new AcquisitionManager();
        $acquisitionLigneManager = new AcquisitionLigneManager();
        $fournisseurManager = new FournisseurManager();
        $categorieManager = new CategorieManager();
        $equipementManager = new EquipementManager();
        
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
                $response = match ($action) {
                    'valider'   => $this->performValidation($acquisition),
                    'update'    => $this->handleUpdateAction($request, $acquisition, $form_errors),
                    'add_ligne' => $this->handleAddLigneAction($request, $acquisition, $form_errors, $ligneData),
                    default     => null,
                };
                
                if ($response !== null) {
                    return $response;
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
     * [REFACTOR VAGUE 3] Handler de l'action 'update'.
     *
     * Extrait de update() pour isoler la logique de mise à jour de l'acquisition
     * (fournisseur, référence facture, date, upload PDF).
     *
     * @param array $acquisition  Muté par référence : fournisseur_id,
     *                            facture_reference, facture_date,
     *                            facture_document (afin que le template
     *                            réaffiche les valeurs saisies en cas d'erreur).
     * @param array $form_errors  Rempli par référence en cas d'erreur.
     *
     * @return Response|null  RedirectResponse en cas de succès, null si on doit
     *                        réafficher le formulaire avec les erreurs.
     */
    private function handleUpdateAction(
        Request $request,
        array &$acquisition,
        array &$form_errors,
    ): ?Response {
        $acquisitionManager = new AcquisitionManager();
        $fournisseurManager = new FournisseurManager();

        $fournisseurNom = trim((string) $request->request->get('fournisseur_nom', ''));
        $factureReference = trim((string) $request->request->get('facture_reference', ''));
        $factureDate = $request->request->get('facture_date');

        // [ROBUSTESSE] Vérifier l'unicité de la référence AVANT l'update
        if ($factureReference === '') {
            $form_errors['facture_reference'] = 'La référence de facture est obligatoire.';
        } else {
            $existing = $acquisitionManager->findOneByCriteria(['facture_reference' => $factureReference]);
            if ($existing && (int) $existing['id'] !== (int) $acquisition['id']) {
                $form_errors['facture_reference'] = 'Cette référence de facture est déjà utilisée par une autre acquisition.';
            }
        }

        // Gestion du téléchargement du PDF (seulement si pas d'erreur bloquante)
        $uploadedFacture = $request->files->get('facture_document');
        if (empty($form_errors['facture_reference']) && $uploadedFacture !== null) {
            $uploader = $this->factureUploader();
            try {
                $newFacture = $uploader->upload($uploadedFacture);
                
                // Supprimer l'ancienne facture (best-effort)
                if (!empty($acquisition['facture_document'])) {
                    $uploader->delete($acquisition['facture_document']);
                }
                
                $acquisition['facture_document'] = $newFacture;
            } catch (FactureUploadException $e) {
                $form_errors['facture_document'] = $e->getMessage();
            }
        }

        // [ROBUSTESSE] Ne mettre à jour le fournisseur que si pas d'erreur
        if (!empty($form_errors)) {
            return null;
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
            return $this->redirectTo("/admin/acquisitions/acquisition_modification-{$acquisition['id']}");
        } catch (\Throwable $e) {
            // [SÉCURITÉ] Log serveur, message générique au client
            error_log(sprintf(
                '[AcquisitionController] Update failed for acquisition id=%s: %s in %s:%d',
                $acquisition['id'],
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $form_errors['general'] = 'Une erreur est survenue lors de la mise à jour. Merci de réessayer.';
            return null;
        }
    }

    /**
     * [REFACTOR VAGUE 3] Handler de l'action 'add_ligne'.
     *
     * Extrait de update() pour isoler l'ajout d'une ligne d'acquisition
     * (whitelist, validation, catégorie, save).
     *
     * @param array $acquisition  Non muté (lecture seule de l'id).
     * @param array $form_errors  Rempli par référence en cas d'erreur.
     * @param array $ligneData    Rempli par référence : permet au template de
     *                            réafficher les valeurs saisies en cas d'erreur.
     *
     * @return Response|null  RedirectResponse en cas de succès, null si on doit
     *                        réafficher le formulaire avec les erreurs.
     */
    private function handleAddLigneAction(
        Request $request,
        array $acquisition,
        array &$form_errors,
        array &$ligneData,
    ): ?Response {
        $acquisitionLigneManager = new AcquisitionLigneManager();

        // [SÉCURITÉ] Whitelist des champs — empêche l'injection de clés
        // arbitraires (id, acquisition_id, equipements_generes) via POST forgé.
        // Sans ce filtre, un attaquant pouvait écraser des colonnes sensibles
        // par mass-assignment.
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

        if ($ligne['reference'] === '') {
            $form_errors['ligne_reference'] = 'Veuillez remplir les champs de la ligne.';
            return null;
        }

        // Validation centralisée, puis remap des clés courtes
        // (reference, designation…) vers les clés attendues par
        // acquisition_form.twig (ligne_reference, ligne_designation…).
        $lineErrors = $this->validator()->validateLigne($ligne);
        $form_errors = array_merge(
            $form_errors,
            $this->prefixLigneErrors($lineErrors)
        );

        if (!empty($form_errors)) {
            return null;
        }

        try {
            $acquisitionProcess = new AcquisitionProcess();
            $ligne['categorie_id'] = $acquisitionProcess->categorie_process($ligne);
            $ligne['acquisition_id'] = $acquisition['id'];
            $ligne['equipements_generes'] = 0;

            $acquisitionLigneManager->save($ligne);
            $this->session->getFlashBag()->add('success', '✅ Ligne ajoutée avec succès.');
            return $this->redirectTo("/admin/acquisitions/acquisition_modification-{$acquisition['id']}");
        } catch (DuplicateReferenceException $e) {
            // [ROBUSTESSE] Race condition : la pré-vérification
            // findByReference() a passé, mais la contrainte UNIQUE
            // a rejeté l'INSERT. On affiche l'erreur sur le bon champ.
            $form_errors['ligne_reference'] = 'Cette référence existe déjà. Veuillez en saisir une autre.';
            return null;
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AcquisitionController] Add ligne failed for acquisition id=%s: %s in %s:%d',
                $acquisition['id'],
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $form_errors['ligne_general'] = 'Une erreur est survenue lors de l\'ajout de la ligne.';
            return null;
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
        
        $acquisitionManager = new AcquisitionManager();
        $acquisition = $acquisitionManager->findId($id);
        if (!$acquisition) {
            $this->session->getFlashBag()->add('error', 'Acquisition non trouvée.');
            return $this->redirectTo('/admin/acquisitions');
        }
        
        // [REFACTOR VAGUE 6] Logique de suppression extraite dans un handler
        // dédié, en symétrie avec create() / update().
        // Convention adaptée : ce handler retourne toujours une Response
        // (jamais null — pas de rendu de formulaire en cas d'erreur).
        return $this->handleDeleteAction($acquisition);
    }
    
    /**
    * [REFACTOR VAGUE 6] Handler de l'action 'delete'.
    *
    * Extrait de delete() pour symétrie avec handleCreateAction() /
    * handleUpdateAction() / handleAddLigneAction().
    *
    * Contrairement aux autres handlers, celui-ci retourne toujours une
    * Response : il n'y a pas de "rendu de formulaire en cas d'erreur",
    * toutes les branches redirigent avec un flash.
    *
    * @param array $acquisition  Acquisition chargée (avec 'id',
    *                            'est_validee', 'facture_document').
    */
    private function handleDeleteAction(array $acquisition): Response
    {
        $id = (int) $acquisition['id'];
        
        // [MÉTIER] Seuls les brouillons peuvent être supprimés
        if ($acquisition['est_validee']) {
            $this->session->getFlashBag()->add(
                'error',
                "Impossible de supprimer une acquisition validée. Elle contient des équipements générés."
            );
            return $this->redirectTo("/admin/acquisitions/acquisition-{$id}");
        }
        
        // [SÉCURITÉ] Refuser si une ligne a déjà généré des équipements
        $acquisitionLigneManager = new AcquisitionLigneManager();
        $lignes = $acquisitionLigneManager->findByAcquisition($id);
        foreach ($lignes as $ligne) {
            if ($ligne['equipements_generes'] == 1) {
                $this->session->getFlashBag()->add(
                    'error',
                    "Impossible de supprimer cette acquisition : certaines lignes ont généré des équipements."
                );
                return $this->redirectTo("/admin/acquisitions/acquisition_modification-{$id}");
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
            $acquisitionManager = new AcquisitionManager();
            $acquisitionManager->delete($id);
            
            $this->session->getFlashBag()->add('success', "L'acquisition #{$id} a été supprimée.");
            return $this->redirectTo('/admin/acquisitions');
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
            return $this->redirectTo("/admin/acquisitions/acquisition-{$id}");
        }
    }

    public function show(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $acquisitionManager = new AcquisitionManager();
        $fournisseurManager = new FournisseurManager();
        $acquisitionLigneManager = new AcquisitionLigneManager();

        $id = (int) $request->get('id');
        if ($id <= 0) {
            return $this->redirectTo('/admin/acquisitions');
        }

        $acquisition = $acquisitionManager->findId($id);
        if (!$acquisition) {
            return $this->redirectTo('/admin/acquisitions');
        }

        $acquisition['lignes'] = $acquisitionLigneManager->findByAcquisition($acquisition['id']);

        return $this->render('acquisition_show.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll(),
        ]);
    }

    public function valider(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        // [SÉCURITÉ] Vérification CSRF (avant génération des équipements)
        $this->validateCsrf($request);

        $id = (int) $request->get('id');
        $acquisitionManager = new AcquisitionManager();
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
    * Instancie le service d'upload.
    *
    * Le chemin est résolu à la construction (issue #40, D1-a) :
    * le service ne connaît pas la structure du projet.
    */
    protected function factureUploader(): FactureUploader
    {
        return new FactureUploader($this->getUploadsDir());
    }
}