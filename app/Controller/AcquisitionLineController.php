<?php

namespace Epiclub\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AcquisitionLineController extends AbstractController
{
    public function modifyLine(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $acquisitionLigneManager = new AcquisitionLigneManager();
        $categorieManager = new CategorieManager();

        $id = $request->get('id');
        if (!$id) {
            $this->session->getFlashBag()->add('error', 'ID de ligne manquant.');
            return new RedirectResponse('/admin/acquisitions');
        }

        $ligne = $acquisitionLigneManager->findId($id);
        if (!$ligne) {
            $this->session->getFlashBag()->add('error', 'Ligne non trouvée.');
            return new RedirectResponse('/admin/acquisitions');
        }

        // Vérifier si l'acquisition est validée
        $acquisitionManager = new AcquisitionManager();
        $acquisition = $acquisitionManager->findId($ligne['acquisition_id']);
        if ($acquisition && $acquisition['est_validee'] == 1) {
            $this->session->getFlashBag()->add('error', 'Cette acquisition est validée, les lignes ne peuvent plus être modifiées.');
            return new RedirectResponse("/admin/acquisitions/acquisition-{$acquisition['id']}");
        }

        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            $reference = trim($request->request->get('reference'));
            $designation = trim($request->request->get('designation'));
            $categorie_libelle = trim($request->request->get('categorie_libelle'));
            $nombre = (int) $request->request->get('nombre');
            $regrouper_en_lot = $request->request->has('regrouper_en_lot') ? 1 : 0;

            // Validations
            if (empty($reference)) {
                $form_errors['reference'] = 'La référence est obligatoire.';
            } elseif ($acquisitionLigneManager->findByReference($reference, $id)) {
                $form_errors['reference'] = 'Cette référence existe déjà.';
            }

            if (empty($designation)) {
                $form_errors['designation'] = 'Le libellé est obligatoire.';
            }
            if (empty($categorie_libelle)) {
                $form_errors['categorie_libelle'] = 'La catégorie est obligatoire.';
            }
            if ($nombre < 1) {
                $form_errors['nombre'] = 'Le nombre doit être supérieur à 0.';
            }

            if (empty($form_errors)) {
                // Traiter la catégorie
                $acquisitionProcess = new \Epiclub\Process\AcquisitionProcess();
                $categorie_id = $acquisitionProcess->categorie_process(['categorie_libelle' => $categorie_libelle]);

                // Mettre à jour la ligne
                $ligne['reference'] = $reference;
                $ligne['designation'] = $designation;
                $ligne['categorie_id'] = $categorie_id;
                $ligne['nombre'] = $nombre;
                $ligne['regrouper_en_lot'] = $regrouper_en_lot;
                // equipements_generes est conservé (0 si non généré)

                $acquisitionLigneManager->save($ligne);

                $this->session->getFlashBag()->add('success', 'Ligne modifiée avec succès.');
                return new RedirectResponse("/admin/acquisitions/acquisition_modification-{$ligne['acquisition_id']}");
            }
        }

        // Charger les catégories pour le formulaire
        $categories = $categorieManager->findAll();

        return $this->render('acquisition_ligne_form.twig', [
            'ligne' => $ligne,
            'categories' => $categories,
            'form_errors' => $form_errors,
        ]);
    }

    public function deleteLine(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $id = $request->get('id');
        if (!$id) {
            $this->session->getFlashBag()->add('error', 'ID de ligne manquant.');
            return new RedirectResponse('/admin/acquisitions');
        }

        $acquisitionLigneManager = new AcquisitionLigneManager();
        $ligne = $acquisitionLigneManager->findId($id);
        if (!$ligne) {
            $this->session->getFlashBag()->add('error', 'Ligne non trouvée.');
            return new RedirectResponse('/admin/acquisitions');
        }

        // Vérifier si l'acquisition est validée
        $acquisitionManager = new AcquisitionManager();
        $acquisition = $acquisitionManager->findId($ligne['acquisition_id']);
        if ($acquisition && $acquisition['est_validee'] == 1) {
            $this->session->getFlashBag()->add('error', 'Cette acquisition est validée, les lignes ne peuvent plus être supprimées.');
            return new RedirectResponse("/admin/acquisitions/acquisition-{$acquisition['id']}");
        }

        // Si la ligne a déjà des équipements générés, empêcher la suppression
        if ($ligne['equipements_generes'] == 1) {
            $this->session->getFlashBag()->add('error', 'Cette ligne a déjà généré des équipements, elle ne peut pas être supprimée.');
            return new RedirectResponse("/admin/acquisitions/acquisition_modification-{$ligne['acquisition_id']}");
        }

        $acquisitionLigneManager->delete($id);

        $this->session->getFlashBag()->add('success', 'Ligne supprimée avec succès.');
        return new RedirectResponse("/admin/acquisitions/acquisition_modification-{$ligne['acquisition_id']}");
    }
}