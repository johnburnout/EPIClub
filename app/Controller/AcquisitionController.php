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
        /** post une ligne */
        if ($request->getMethod() === 'POST') {
            $ligne = $request->request->all()['ligne'];
            /** @todo need ligne form validation here */

            $acquisitionProcess = new AcquisitionProcess();
            $ligne['categorie_id'] = $acquisitionProcess->categorie_process($ligne);
            $ligne['acquisition_id'] = $acquisition['id'];
            $ligne['equipements_generes'] = 0;

            if (empty($form_errors)) {
                $acquisitionLigneManager->save($ligne);
                return $this->redirectTo("/admin/acquisitions/acquisition_modification-$acquisition[id]");
            }
            /** @todo else form_error $line here */

            // NOPE  dd('Creer le(s) equipement(s) ici');
            # epi.reference = "facture_date(Y)-acquisition.id#epi.id" -> format(<facture_date_year>-<aquisition.id>-<categorie.id>#<epi.id>)
            // NOPE  dd('Generer le(s) qrcode(s) ici');
            // if ok flash(success, Vous pouvez imprimer les Qrcodes)
        }

        return $this->render('acquisition_form.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll(),
            'categories' => $categorieManager->findAll()
        ]);
    }

    public function show(Request $request)
    {
        $acquisitionManager = new AcquisitionManager();
        $fournisseurManager = new FournisseurManager();

        $acquisition = $acquisitionManager->findId($request->get('id'));

        return $this->render('acquisition_show.twig', [
            'acquisition' => $acquisition,
            'fournisseurs' => $fournisseurManager->findAll()
        ]);
    }
}
