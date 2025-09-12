<?php

namespace Epiclub\Controller;

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
        
        $categories = [];
        $equipement_statuts = EquipementStatuts::forSelect();
        $equipement_etats = EquipementEtats::forSelect();
        $equipement = [];

        if ($request->get('id')) {
            $equipement = [];
        }

        return $this->render('equipement_form.twig', [
            'categories' => $categories,
            'equipement_statuts' => $equipement_statuts,
            'equipement_etats' => $equipement_etats,
            'equipement' => $equipement
        ]);
    }

    public function delete(Request $request) {}
}
