<?php

namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
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
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
    }
}
