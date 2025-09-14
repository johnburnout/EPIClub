<?php

namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Enum\EquipementEtats;
use Epiclub\Enum\EquipementStatuts;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;

class EquipementController extends AbstractController
{
    public function list(Request $request)
    {
        $equipementManager = new EquipementManager();
        $equipements = $equipementManager->findAll();

        return $this->render('equipement_list.twig', [
            'equipements' => $equipements
        ]);
    }

    public function show(Request $request)
    {
        $equipementManager = new EquipementManager();
        $equipement = $equipementManager->findId($request->get('id'));

        return $this->render('equipement_detail.twig', [
            'equipement' => $equipement
        ]);
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $categorieManager = new CategorieManager();
        $equipementManager = new EquipementManager();
        $equipement = [];
        $form_errors = [];

        if ($id = $request->get('id')) {
            $equipement = $equipementManager->findId($id);
        }

        if ($request->getMethod() === 'POST') {
            /** @todo need validation here */

            if (empty($form_errors)) {
                $equipement = array_merge(
                    $equipement,
                    [
                        'categorie_id' => $request->request->get('categorie_id'),
                        'reference' => $request->request->get('reference'),
                        'date_achat' => $request->request->get('date_achat'),
                        'statut_id' => $request->request->get('statut_id'),
                        'etat_usure_id' => $request->request->get('etat_usure_id'),
                    ]
                );
                $equipementManager->save($equipement);
                /** @todo flash success */
                return $this->redirectTo("/equipements");
            }

            /** @todo else error something wrong... */
        }

        return $this->render('equipement_form.twig', [
            'categories' => $categorieManager->findAll(),
            'equipement_statuts' => EquipementStatuts::forSelect(),
            'equipement_etats' => EquipementEtats::forSelect(),
            'equipement' => $equipement,
            'form_errors' => $form_errors
        ]);
    }

    /**
     * @deprecated Why we need this?
     */
    public function delete(Request $request)
    {
        throw new \Exception("Error Processing Request", 1);
    }
}
