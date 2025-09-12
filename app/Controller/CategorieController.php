<?php

namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Enum\EquipementEtats;
use Epiclub\Enum\EquipementStatuts;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;

class CategorieController extends AbstractController
{
    public function list(Request $request)
    {
        $categorieManager = new CategorieManager();
        $categories = $categorieManager->findAll();

        return $this->render('categorie_list.twig', [
            'categories' => $categories
        ]);
    }

    public function show(Request $request)
    {
        $categorieManager = new CategorieManager();
        $categorie = $categorieManager->findId($request->get('id'));

        return $this->render('categorie_show.twig', [
            'categorie' => $categorie
        ]);
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $categorie = [];

        if ($id = $request->get('id')) {
            $categorieManager = new CategorieManager();
            $categorie = $categorieManager->findId($id);
        }

        return $this->render('categorie_form.twig', [
            'categorie' => $categorie
        ]);
    }

    public function delete(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
    }
}
