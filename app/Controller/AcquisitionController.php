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
use Epiclub\Exception\DuplicateReferenceException;
use Epiclub\Exception\NotFoundException;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AcquisitionController extends AbstractController
{
    /** Dernier message d'erreur d'upload, à afficher via $form_errors. */
    private ?string $lastUploadError = null;

    /**
     * Instancie le validator (issue #34).
     *
     * Pas de cache : le constructeur ne fait aucune I/O, l'instanciation
     * est triviale et évite tout état persistant entre appels.
     */
    private function validator(): AcquisitionValidator
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

                // [REFACTOR VAGUE 4] Dispatch vers un handler dédié (symétrie avec update()).
                // Convention : un handler retourne ?Response :
                //   - Response : redirection immédiate (succès)
                //   - null     : on continue vers le rendu du formulaire (erreurs ou no-op)
                $response = match ($action) {
                    'create' => $this->handleCreateAction($request, $acquisition, $form_errors),
                    default  => null,
                };

                if ($response !== null) {
                    return $response;
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
     * [REFACTOR VAGUE 4] Handler de l'action 'create'.
     *
     * Extrait de create() pour isoler la création d'une acquisition
     * (whitelist, unicité référence, upload facture, process métier).
     *
     * @param array $acquisition  Muté par référence : rempli avec les champs
     *                            whitelistés + 'saisie_par' + 'facture_document',
     *                            afin que le template réaffiche la saisie en
     *                            cas d'erreur.
     * @param array $form_errors  Rempli par référence en cas d'erreur.
     *
     * @return Response|null  RedirectResponse en cas de succès, null si on doit
     *                        réafficher le formulaire avec les erreurs.
     */
    private function handleCreateAction(
        Request $request,
        array &$acquisition,
        array &$form_errors,
    ): ?Response {
        // [SÉCURITÉ] Whitelist des champs — empêche l'injection de clés
        // arbitraires (id, est_validee, saisie_par, ...) via POST forgé.
        $acquisition = [
            'facture_reference' => trim((string) $request->request->get('facture_reference', '')),
            'facture_date'      => $request->request->get('facture_date'),
            'fournisseur_nom'   => trim((string) $request->request->get('fournisseur_nom', '')),
        ];
        $acquisition['saisie_par'] = $this->session->get('user')['id'];
        $acquisition['facture_document'] = null;

        // [ROBUSTESSE] Vérifier l'unicité de la référence AVANT l'insert
        $factureReference = $acquisition['facture_reference'];
        if ($factureReference === '') {
            $form_errors['facture_reference'] = 'La référence de facture est obligatoire.';
        } else {
            $acquisitionManager = new AcquisitionManager();
            if ($acquisitionManager->findOneByCriteria(['facture_reference' => $factureReference])) {
                $form_errors['facture_reference'] = 'Cette référence de facture existe déjà. Merci d\'en choisir une autre.';
            }
        }

        // Téléchargement de la facture (seulement si pas d'erreur bloquante)
        if (empty($form_errors)) {
            $factureDocument = $this->uploadFacture($request->files->get('facture_document'));
            if ($factureDocument === false) {
                $form_errors['facture_document'] = $this->lastUploadError
                ?? 'Erreur lors du téléchargement du fichier.';
            } elseif ($factureDocument !== null) {
                $acquisition['facture_document'] = $factureDocument;
            }
        }

        // [ROBUSTESSE] Ne lancer le process métier que si pas d'erreur
        if (!empty($form_errors)) {
            return null;
        }

        $acquisitionProcess = new AcquisitionProcess();
        try {
            if ($id = $acquisitionProcess->acquisition_process($acquisition)) {
                $acquisition['id'] = $id;
                $this->session->getFlashBag()->add(
                    'success',
                    '✅ Acquisition créée avec succès. Vous pouvez maintenant ajouter des lignes.'
                );
                return $this->redirectTo("/admin/acquisitions/acquisition_modification-$id");
            }
            $form_errors['general'] = 'Erreur lors de la création de l\'acquisition.';
            return null;
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AcquisitionController] Create failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $form_errors['general'] = 'Une erreur est survenue lors de la création. Merci de réessayer.';
            return null;
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
                // Convention : un handler retourne ?Response :
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
            $factureDocument = $this->uploadFacture($uploadedFacture);
            if ($factureDocument === false) {
                $form_errors['facture_document'] = $this->lastUploadError
                ?? 'Erreur lors du téléchargement du fichier.';
            } else {
                // [SÉCURITÉ] Ne plus masquer les erreurs avec @unlink
                if (!empty($acquisition['facture_document'])) {
                    $oldFilePath = $this->getUploadsDir() . $acquisition['facture_document'];
                    if (file_exists($oldFilePath) && !unlink($oldFilePath)) {
                        error_log("[AcquisitionController] Failed to delete old facture: $oldFilePath");
                    }
                }
                $acquisition['facture_document'] = $factureDocument;
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

        // [SÉCURITÉ] Vérification CSRF (avant toute suppression de fichier ou d'enregistrement)
        $this->validateCsrf($request);

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

            // Supprimer la facture associée si elle existe
            if (!empty($acquisition['facture_document'])) {
                $facturePath = $this->getUploadsDir() . $acquisition['facture_document'];
                if (file_exists($facturePath) && !unlink($facturePath)) {
                    error_log("[AcquisitionController] Failed to delete facture: $facturePath");
                }
            }

            // Puis l'acquisition
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
     * [ROBUSTESSE] Calcule la taille max réellement acceptée pour un upload :
     *   min(post_max_size, upload_max_filesize, 10 Mo métier)
     */
    private function getMaxUploadBytes(): int
    {
        $postMax   = $this->parseIniSize(ini_get('post_max_size'));
        $uploadMax = $this->parseIniSize(ini_get('upload_max_filesize'));
        $business  = 10 * 1024 * 1024;

        $limits = array_filter([$postMax, $uploadMax, $business], fn($v) => $v > 0);
        return $limits ? min($limits) : $business;
    }

    /**
     * [ROBUSTESSE] Upload d'une facture via Symfony UploadedFile.
     *
     * @return string|null  Chemin relatif ('factures/xxx.pdf') en cas de succès,
     *                      null si aucun fichier, false en cas d'erreur.
     */
    private function uploadFacture(?UploadedFile $file): string|false|null
    {
        $this->lastUploadError = null;

        if ($file === null) {
            return null;
        }

        if (!$file->isValid()) {
            $this->lastUploadError = 'Erreur de téléchargement : ' . $file->getErrorMessage();
            return false;
        }

        $maxBytes = $this->getMaxUploadBytes();
        if ($file->getSize() > $maxBytes) {
            $this->lastUploadError = 'Le fichier dépasse la taille maximum autorisée (' . round($maxBytes / 1024 / 1024) . ' Mo).';
            return false;
        }

        $mimeType = $file->getMimeType();
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($mimeType, $allowedTypes, true)) {
            $this->lastUploadError = 'Type de fichier non autorisé. Formats acceptés : PDF, JPG, PNG.';
            return false;
        }

        $uploadDir = $this->getUploadsDir() . 'factures/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $this->lastUploadError = 'Impossible de créer le dossier de téléchargement.';
                return false;
            }
        }

        if (!is_writable($uploadDir)) {
            $this->lastUploadError = 'Le dossier de téléchargement n\'est pas accessible en écriture.';
            return false;
        }

        $extension = $file->guessExtension() ?: 'bin';
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?: 'bin';
        $filename = 'facture_' . bin2hex(random_bytes(8)) . '.' . $extension;

        try {
            $file->move($uploadDir, $filename);
        } catch (\Throwable $e) {
            error_log('[AcquisitionController] move failed: ' . $e->getMessage());
            $this->lastUploadError = 'Erreur lors de l\'enregistrement du fichier.';
            return false;
        }

        return 'factures/' . $filename;
    }
}