<?php

namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CategorieController extends AbstractController
{
    private const IMAGE_DIR = '/../../public/images/';

    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $categorieManager = new CategorieManager();
        $categories = $categorieManager->findAll();

        return $this->render('categorie_list.twig', [
            'categories' => $categories
        ]);
    }

    public function show(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/categories');
        }

        $categorieManager = new CategorieManager();
        $categorie = $categorieManager->findId($id);

        if (!$categorie) {
            $this->session->getFlashBag()->add('error', "La catégorie demandée n'existe pas.");
            return new RedirectResponse('/admin/categories');
        }

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

        // --- Récupération de l'ID depuis la requête (GET ou POST) ---
        $id = $this->getValidId($request);
        if ($id !== null) {
            $categorie = $categorieManager->findId($id);
            if (!$categorie) {
                // ID syntaxiquement valide mais inexistant en BDD
                $this->session->getFlashBag()->add('error', "La catégorie demandée n'existe pas.");
                return new RedirectResponse('/admin/categories');
            }
        }

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement (y compris upload)
            $this->validateCsrf($request);

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
                        $image->move(__DIR__ . self::IMAGE_DIR, $newFilename);

                        // Supprimer l'ancienne image si on est en mise à jour
                        if (!empty($categorie['image']) && $categorie['image'] !== $newFilename) {
                            $oldImagePath = __DIR__ . self::IMAGE_DIR . $categorie['image'];
                            if (file_exists($oldImagePath)) {
                                @unlink($oldImagePath);
                            }
                        }

                        $categorie['image'] = $newFilename;
                    } catch (FileException $e) {
                        error_log('[CategorieController] Upload failed: ' . $e->getMessage());
                        $form_errors['image'] = "Erreur lors du téléversement de l'image.";
                    }
                }

                if (empty($form_errors)) {
                    $categorieManager->save($categorie);
                    $this->session->getFlashBag()->add('success', "La catégorie a été enregistrée.");
                    return $this->redirectTo("/admin/categories");
                }
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

        // [SÉCURITÉ] Action destructive réservée à POST
        if (!$request->isMethod('POST')) {
            return new RedirectResponse('/admin/categories');
        }

        // [SÉCURITÉ] Vérification CSRF
        $this->validateCsrf($request);

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/categories');
        }

        $categorieManager = new CategorieManager();
        $categorie = $categorieManager->findId($id);

        if (!$categorie) {
            $this->session->getFlashBag()->add('error', "La catégorie demandée n'existe pas.");
            return new RedirectResponse('/admin/categories');
        }

        if ($categorieManager->hasEquipements($id)) {
            $this->session->getFlashBag()->add('error', "Impossible de supprimer cette catégorie car elle a des équipements associés.");
            return new RedirectResponse('/admin/categories');
        }

        // [MÉTIER] Hard delete => on supprime aussi le fichier image associé
        $imageToDelete = null;
        if (!empty($categorie['image'])) {
            $imageToDelete = __DIR__ . self::IMAGE_DIR . $categorie['image'];
        }

        $categorieManager->delete($id);

        if ($imageToDelete !== null && file_exists($imageToDelete)) {
            if (!@unlink($imageToDelete)) {
                error_log('[CategorieController] Failed to delete image: ' . $imageToDelete);
            }
        }

        $this->session->getFlashBag()->add('success', "La catégorie '{$categorie['libelle']}' a été supprimée.");

        return new RedirectResponse('/admin/categories');
    }
}