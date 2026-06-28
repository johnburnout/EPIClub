<?php

namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse; // ← AJOUTER CET IMPORT

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

        $categorieManager = new CategorieManager();

        $categorie = [];
        $form_errors = [];

        if ($id = $request->get('id')) {

            $categorie = $categorieManager->findId($id);
        }

        if ($request->getMethod() === 'POST') {
            /** @todo Need validation here */
            if (empty($form_errors)) {
                $categorie = array_merge(
                    $categorie,
                    [
                        'libelle' => $request->request->get('libelle'),
                        'est_epi' => $request->request->has('est_epi') ? 1 : 0,
                        'description' => $request->request->get('description'),
                    ]
                );
                if ($image = $request->files->get('image')) {
                    $originalFilename = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
                    $newFilename = $originalFilename . '-' . uniqid() . '.' . $image->guessExtension();
                    try {
                        $image->move(__DIR__ . '/../../public/images', $newFilename);
                        $categorie['image'] = $newFilename;
                    } catch (FileException $e) {
                        // ... handle exception if something happens during file upload
                    }
                }

                $categorieManager->save($categorie);
                return $this->redirectTo("/admin/categories");
            }
        }

        return $this->render('categorie_form.twig', [
            'categorie' => $categorie,
            'form_errors' => $form_errors
        ]);
    }

    public function delete(Request $request)
    {
        $response = $this->deniAccessUnlessGranted('ROLE_ADMIN');
        if ($response) {
            return $response;
        }
        
        $id = $request->query->get('id');
        if (!$id) {
            $this->session->getFlashBag()->add('error', 'ID catégorie manquant.');
            return new RedirectResponse("/admin/categories");
        }
        
        $categorieManager = new CategorieManager();
        $categorie = $categorieManager->findId($id);
        
        if (!$categorie) {
            $this->session->getFlashBag()->add('error', "La catégorie demandée n'existe pas.");
            return new RedirectResponse("/admin/categories");
        }
        
        // Vérifier si la catégorie a des équipements associés
        if ($categorieManager->hasEquipements($id)) {
            $this->session->getFlashBag()->add('error', "Impossible de supprimer cette catégorie car elle a des équipements associés.");
            return new RedirectResponse("/admin/categories");
        }
        
        $categorieManager->delete($id);
        
        $this->session->getFlashBag()->add('success', "La catégorie '{$categorie['libelle']}' a été supprimée.");
        
        return new RedirectResponse("/admin/categories");
    }
}