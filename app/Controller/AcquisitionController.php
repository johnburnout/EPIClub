<?php

namespace Epiclub\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AcquisitionController extends AbstractController
{
    public function list(Request $request)
    {
        $acquisitionManager = new AcquisitionManager();
        $acquisitions = $acquisitionManager->findAll();

        return $this->render('acquisition_list.twig', [
            'acquisitions' => $acquisitions
        ]);
    }

    public function create(Request $request)
    {
        $fournisseurManager = new FournisseurManager();
        $categorieManager = new CategorieManager();
        $acquisition = [];
        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            $action = $request->request->get('action');
            
            if ($action === 'create') {
                $acquisition = $request->request->all();
                $acquisition['saisie_par'] = $this->session->get('user')['id'];
                
                $factureDocument = $this->uploadFacture($_FILES['facture_document'] ?? null);
                if ($factureDocument === false) {
                    $form_errors['facture_document'] = 'Erreur lors du téléchargement du fichier. Formats acceptés : PDF, JPG, PNG (max 10 Mo).';
                    $acquisition['facture_document'] = null;
                } else {
                    $acquisition['facture_document'] = $factureDocument;
                }
                
                if (empty($form_errors)) {
                    $acquisitionProcess = new AcquisitionProcess();
                    if ($id = $acquisitionProcess->acquisition_process($acquisition)) {
                        $acquisition['id'] = $id;
                        $this->session->getFlashBag()->add('success', '✅ Acquisition créée avec succès. Vous pouvez maintenant ajouter des lignes et télécharger la facture.');
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
            'form_errors' => $form_errors
        ]);
    }

    public function update(Request $request)
    {
        $acquisitionManager = new AcquisitionManager();
        $acquisitionLigneManager = new AcquisitionLigneManager();
        $fournisseurManager = new FournisseurManager();
        $categorieManager = new CategorieManager();
        $equipementManager = new EquipementManager();
        
        $acquisition = $acquisitionManager->findId($request->get('id'));
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
                } catch (\Exception $e) {
                    $this->session->getFlashBag()->add('error', 'Erreur lors de la validation : ' . $e->getMessage());
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
                        if (!empty($acquisition['facture_document'])) {
                            $oldFilePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $acquisition['facture_document'];
                            if (file_exists($oldFilePath)) {
                                unlink($oldFilePath);
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
            'ligne_data' => $ligneData ?? []
        ]);
    }

    public function show(Request $request)
    {
        $acquisitionManager = new AcquisitionManager();
        $fournisseurManager = new FournisseurManager();
        $acquisitionLigneManager = new AcquisitionLigneManager();

        $acquisition = $acquisitionManager->findId($request->get('id'));
        if (!$acquisition) {
            return $this->redirectTo("/admin/acquisitions");
        }

        $acquisition['lignes'] = $acquisitionLigneManager->findByAcquisition($acquisition['id']);

        return $this->render('acquisition_show.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll()
        ]);
    }

    public function valider(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $id = $request->get('id');
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
        } catch (\Exception $e) {
            $this->session->getFlashBag()->add('error', 'Erreur lors de la validation : ' . $e->getMessage());
        }
        
        return $this->redirectTo("/admin/acquisitions/acquisition-{$id}");
    }

    public function serveFile(Request $request): BinaryFileResponse
    {
        $path = $request->attributes->get('path');
        
        $path = str_replace(['..', '/../', '\\'], '', $path);
        
        $filePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $path;
        
        if (!file_exists($filePath)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Fichier non trouvé');
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        return new BinaryFileResponse($filePath, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"'
        ]);
    }

    private function uploadFacture(?array $file)
    {
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            return false;
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        
        if (!in_array($mimeType, $allowedTypes, true)) {
            return false;
        }
        
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/factures/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'facture_' . uniqid() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return false;
        }
        
        return 'factures/' . $filename;
    }
}