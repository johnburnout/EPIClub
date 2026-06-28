<?php

namespace Epiclub\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\Request;
use Epiclub\Domain\CategorieManager;

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
        if ($request->getMethod() === 'POST') {
            /** @todo need validation here */

            $acquisition = $request->request->all();
            $acquisition['saisie_par'] = $this->session->get('user')['id'];

            /** @todo need facture pdf or image ? loader here */
            $acquisition['facture_document'] = '';

            $acquisitionProcess = new AcquisitionProcess();
            if ($id = $acquisitionProcess->acquisition_process($acquisition)) {
                $acquisition['id'] = $id;
                return $this->redirectTo("/admin/acquisitions/acquisition_modification-$id");
            }

            /** @todo else error something wrong... */
        }

        return $this->render('acquisition_form.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll(),
            'categories' => $categorieManager->findAll()
        ]);
    }

    public function update(Request $request)
    {
        $acquisitionManager = new AcquisitionManager();
        $acquisitionLigneManager = new AcquisitionLigneManager();
        $fournisseurManager = new FournisseurManager();
        $categorieManager = new CategorieManager();
        
        $acquisition = $acquisitionManager->findId($request->get('id'));
        $acquisition['lignes'] = $acquisitionLigneManager->findByAcquisition($acquisition['id']);
        $form_errors = [];
        $ligneData = []; // ✅ Ajouter pour conserver les données saisies
        
        if ($request->getMethod() === 'POST') {
            $ligne = $request->request->all()['ligne'];
            $ligneData = $ligne; // ✅ Conserver les données saisies
            
            // VÉRIFICATION DE L'UNICITÉ DE LA RÉFÉRENCE
            $reference = $ligne['reference'] ?? '';
            
            if (empty($reference)) {
                $form_errors['ligne_reference'] = 'La référence est obligatoire.';
            } elseif ($acquisitionLigneManager->findByReference($reference)) {
                $form_errors['ligne_reference'] = 'Cette référence existe déjà. Veuillez en saisir une autre.';
            }
            
            // Autres validations
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
                return $this->redirectTo("/admin/acquisitions/acquisition_modification-$acquisition[id]");
            }
        }
        
        return $this->render('acquisition_form.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll(),
            'categories' => $categorieManager->findAll(),
            'form_errors' => $form_errors,
            'ligne_data' => $ligneData // ✅ Passer les données saisies au template
        ]);
    }

    public function show(Request $request)
    {
        $acquisitionManager = new AcquisitionManager();
        $fournisseurManager = new FournisseurManager();
        $acquisitionLigneManager = new AcquisitionLigneManager(); // ✅ AJOUTER
        
        $acquisition = $acquisitionManager->findId($request->get('id'));
        if (!$acquisition) {
            return $this->redirectTo("/admin/acquisitions");
        }
        
        // Charger les lignes de l'acquisition
        $acquisition['lignes'] = $acquisitionLigneManager->findByAcquisition($acquisition['id']);
        
        return $this->render('acquisition_show.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll()
        ]);
    }
}
