<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AcquisitionController extends AbstractController
{
    public function list(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $acquisitionManager = new AcquisitionManager();
        $acquisitions = $acquisitionManager->findAll();

        // Filtrer pour n'afficher que les acquisitions validées
        $acquisitions = array_filter($acquisitions, function ($a) {
            return $a['est_validee'] == 1;
        });

        return $this->render('acquisition_list.twig', [
            'acquisitions' => $acquisitions,
        ]);
    }

    public function create(Request $request): Response
    {
        // [SÉCURITÉ] Contrôle d'accès manquant
        $this->deniAccessUnlessGranted('ROLE_USER');

        $fournisseurManager = new FournisseurManager();
        $categorieManager = new CategorieManager();
        $acquisition = [];
        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            $action = $request->request->get('action');

            if ($action === 'create') {
                $acquisition = $request->request->all();
                $acquisition['saisie_par'] = $this->session->get('user')['id'];

                $acquisition['facture_document'] = null;

                // [ROBUSTESSE] Utilisation de $request->files (sera affiné en vague 2)
                $factureDocument = $this->uploadFacture($_FILES['facture_document'] ?? null);
                if ($factureDocument === false) {
                    $form_errors['facture_document'] = 'Erreur lors du téléchargement du fichier. Formats acceptés : PDF, JPG, PNG (max 10 Mo).';
                } elseif ($factureDocument !== null) {
                    $acquisition['facture_document'] = $factureDocument;
                }

                if (empty($form_errors)) {
                    $acquisitionProcess = new AcquisitionProcess();
                    if ($id = $acquisitionProcess->acquisition_process($acquisition)) {
                        $acquisition['id'] = $id;
                        $this->session->getFlashBag()->add('success', '✅ Acquisition créée avec succès. Vous pouvez maintenant ajouter des lignes.');
                        return $this->redirectTo("/admin/acquisitions/acquisition_modification-$id");
                    }
                    $form_errors['general'] = 'Erreur lors de la création de l\'acquisition.';
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

    public function update(Request $request): Response
    {
        // [SÉCURITÉ] Contrôle d'accès manquant
        $this->deniAccessUnlessGranted('ROLE_USER');

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
            $action = $request->request->get('action');

            // --- Action : Valider ---
            if ($action === 'valider') {
                if (empty($acquisition['facture_document'])) {
                    $this->session->getFlashBag()->add('error', '❌ Impossible de valider : veuillez d\'abord télécharger la facture.');
                    return $this->redirectTo("/admin/acquisitions/acquisition_modification-{$acquisition['id']}");
                }

                $acquisitionProcess = new AcquisitionProcess();
                try {
                    $acquisitionProcess->validerAcquisition($acquisition['id']);
                    $acquisition['est_validee'] = 1;
                    $acquisitionManager->save($acquisition);
                    $this->session->getFlashBag()->add('success', '✅ Acquisition validée ! Les équipements ont été générés.');
                    return $this->redirectTo("/admin/acquisitions/acquisition-{$acquisition['id']}");
                } catch (\Throwable $e) {
                    // [SÉCURITÉ] Ne pas exposer $e->getMessage() au client
                    error_log(sprintf(
                        '[AcquisitionController] Validation failed (action=valider) for acquisition id=%s: %s',
                        $acquisition['id'],
                        $e->getMessage()
                    ));
                    $this->session->getFlashBag()->add(
                        'error',
                        'Une erreur est survenue lors de la validation. Merci de réessayer ou de contacter un administrateur.'
                    );
                    return $this->redirectTo("/admin/acquisitions/acquisition_modification-{$acquisition['id']}");
                }
            }

            // --- Action : Mise à jour de l'acquisition ---
            if ($action === 'update') {
                $fournisseurNom = $request->request->get('fournisseur_nom');
                $factureReference = $request->request->get('facture_reference');
                $factureDate = $request->request->get('facture_date');

                // Gestion du téléchargement du PDF
                if (isset($_FILES['facture_document']) && $_FILES['facture_document']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $factureDocument = $this->uploadFacture($_FILES['facture_document']);
                    if ($factureDocument === false) {
                        $form_errors['facture_document'] = 'Erreur lors du téléchargement du fichier. Formats acceptés : PDF, JPG, PNG (max 10 Mo).';
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

                // Gestion du fournisseur
                $fournisseur = $fournisseurManager->findOneByCriteria(['nom' => $fournisseurNom]);
                if ($fournisseur) {
                    $fournisseurId = $fournisseur['id'];
                } else {
                    $fournisseurId = $fournisseurManager->save(['nom' => $fournisseurNom]);
                }

                $acquisition['fournisseur_id'] = $fournisseurId;
                $acquisition['facture_reference'] = $factureReference;
                $acquisition['facture_date'] = $factureDate;

                if (empty($form_errors)) {
                    $acquisitionManager->save($acquisition);
                    $this->session->getFlashBag()->add('success', '✅ Acquisition mise à jour avec succès.');
                    return $this->redirectTo("/admin/acquisitions/acquisition_modification-{$acquisition['id']}");
                }
            }

            // --- Action : Ajout d'une ligne ---
            if ($action === 'add_ligne') {
                $ligne = $request->request->all()['ligne'] ?? [];
                $ligneData = $ligne;

                if (!empty($ligne) && !empty($ligne['reference'])) {
                    $ligne['regrouper_en_lot'] = isset($ligne['regrouper_en_lot']) ? 1 : 0;

                    $reference = $ligne['reference'] ?? '';

                    if (empty($reference)) {
                        $form_errors['ligne_reference'] = 'La référence est obligatoire.';
                    } elseif ($acquisitionLigneManager->findByReference($reference)) {
                        $form_errors['ligne_reference'] = 'Cette référence existe déjà. Veuillez en saisir une autre.';
                    }

                    if (empty($ligne['designation'] ?? '')) {
                        $form_errors['ligne_designation'] = 'Le libellé est obligatoire.';
                    }
                    if (empty($ligne['categorie_libelle'] ?? '')) {
                        $form_errors['ligne_categorie'] = 'La catégorie est obligatoire.';
                    }
                    if (empty($ligne['nombre'] ?? 0) || $ligne['nombre'] < 1) {
                        $form_errors['ligne_nombre'] = 'Le nombre doit être supérieur à 0.';
                    }

                    if (empty($form_errors)) {
                        $acquisitionProcess = new AcquisitionProcess();
                        $ligne['categorie_id'] = $acquisitionProcess->categorie_process($ligne);
                        $ligne['acquisition_id'] = $acquisition['id'];
                        $ligne['equipements_generes'] = 0;

                        $acquisitionLigneManager->save($ligne);
                        $this->session->getFlashBag()->add('success', '✅ Ligne ajoutée avec succès.');
                        return $this->redirectTo("/admin/acquisitions/acquisition_modification-{$acquisition['id']}");
                    }
                } else {
                    $form_errors['ligne_reference'] = 'Veuillez remplir les champs de la ligne.';
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

    public function show(Request $request): Response
    {
        // [SÉCURITÉ] Contrôle d'accès manquant
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

        if (empty($acquisition['facture_document'])) {
            $this->session->getFlashBag()->add('error', '❌ Impossible de valider : veuillez d\'abord télécharger la facture.');
            return $this->redirectTo("/admin/acquisitions/acquisition_modification-{$id}");
        }

        try {
            $acquisitionProcess = new AcquisitionProcess();
            $acquisitionProcess->validerAcquisition($id);

            $acquisition['est_validee'] = 1;
            $acquisitionManager->save($acquisition);

            $this->session->getFlashBag()->add('success', '✅ Acquisition validée avec succès ! Les équipements ont été générés.');
        } catch (\Throwable $e) {
            // [SÉCURITÉ] Ne pas exposer $e->getMessage() au client
            error_log(sprintf(
                '[AcquisitionController] Validation failed (action=valider direct) for acquisition id=%s: %s',
                $id,
                $e->getMessage()
            ));
            $this->session->getFlashBag()->add(
                'error',
                'Une erreur est survenue lors de la validation. Merci de réessayer ou de contacter un administrateur.'
            );
        }

        return $this->redirectTo("/admin/acquisitions/acquisition-{$id}");
    }

    public function serveFile(Request $request): BinaryFileResponse
    {
        // [SÉCURITÉ] Contrôle d'accès manquant — critique car expose des factures
        $this->deniAccessUnlessGranted('ROLE_USER');

        $path = (string) $request->attributes->get('path');

        // [SÉCURITÉ] Path traversal correctement bloqué via realpath()
        $uploadsDir = $this->getUploadsDir();
        $realBase = realpath($uploadsDir);
        $realPath = realpath($uploadsDir . ltrim($path, '/'));

        if ($realBase === false
            || $realPath === false
            || !str_starts_with($realPath, $realBase)
        ) {
            throw new NotFoundHttpException('Fichier non trouvé');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($realPath) ?: 'application/octet-stream';

        return new BinaryFileResponse($realPath, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($realPath) . '"',
        ]);
    }

    /**
     * [ROBUSTESSE] Centralise le chemin de base des uploads.
     * À terme : injecter via .env (UPLOADS_DIR) ou constante de config.
     */
    private function getUploadsDir(): string
    {
        $base = $_ENV['UPLOADS_DIR'] ?? ($_SERVER['DOCUMENT_ROOT'] . '/uploads');
        return rtrim($base, '/') . '/';
    }

    private function uploadFacture(?array $file)
    {
        // Aucun fichier téléchargé
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        // Erreur de téléchargement
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->session->getFlashBag()->add('error', 'Erreur de téléchargement : code ' . $file['error']);
            return false;
        }

        // Taille maximum : 10 Mo
        if ($file['size'] > 10 * 1024 * 1024) {
            $this->session->getFlashBag()->add('error', 'Le fichier dépasse la taille maximum autorisée (10 Mo).');
            return false;
        }

        // Vérification du type MIME
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        // [SÉCURITÉ] Formats réduits — retirer msword/docx si non nécessaires (macros)
        $allowedTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ];

        if (!in_array($mimeType, $allowedTypes, true)) {
            $this->session->getFlashBag()->add('error', 'Type de fichier non autorisé. Formats acceptés : PDF, JPG, PNG.');
            return false;
        }

        $uploadDir = $this->getUploadsDir() . 'factures/';

        // Créer le dossier s'il n'existe pas
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $this->session->getFlashBag()->add('error', 'Impossible de créer le dossier de téléchargement.');
                return false;
            }
        }

        // Vérifier les permissions
        if (!is_writable($uploadDir)) {
            $this->session->getFlashBag()->add('error', 'Le dossier de téléchargement n\'est pas accessible en écriture.');
            return false;
        }

        // [SÉCURITÉ] Nom imprévisible (random_bytes au lieu d'uniqid)
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?: 'bin';
        $filename = 'facture_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $filepath = $uploadDir . $filename;

        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            $error = error_get_last();
            error_log('[AcquisitionController] move_uploaded_file failed: ' . ($error['message'] ?? 'unknown'));
            $this->session->getFlashBag()->add('error', 'Erreur lors de l\'enregistrement du fichier.');
            return false;
        }

        return 'factures/' . $filename;
    }
}