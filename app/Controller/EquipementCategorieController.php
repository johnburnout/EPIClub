<?php

namespace Epiclub\Controller;

use Epiclub\Enum\EquipementEtats;
use Epiclub\Enum\EquipementStatuts;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;

class EquipementController extends AbstractController
{
    public function list(Request $request)
    {
        $equipement_categories = [];

        return $this->render('equipement_categorie_list.twig', [
            'equipement_categories' => $equipement_categories
        ]);
    }

    public function show(Request $request)
    {
        $equipement_categorie = [];

        return $this->render('equipement_categorie_detail.twig', [
            'equipement_categorie' => $equipement_categorie
        ]);
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('admin');
        
        $equipement_categories = [];
        $equipement_statuts = EquipementStatuts::forSelect();
        $equipement_etats = EquipementEtats::forSelect();
        $equipement = [];

        if ($request->get('id')) {
            $epi = [];
        }

        return $this->render('equipement_categorie_form.twig', [
            'equipement_categories' => $equipement_categories,
            'equipement_statuts' => $equipement_statuts,
            'equipement_etats' => $equipement_etats,
            'equipement' => $equipement
        ]);
    }

    public function delete(Request $request) {}
}
