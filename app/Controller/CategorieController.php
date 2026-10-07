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
    private const DESCRIPTION_MAX_LENGTH = 2000;

    // ==================================================================
    // [REFACTOR VAGUE 11] Factory manager — mockable en test Unit.
    //
    // Retourne une nouvelle instance concrète. Les sous-classes de test
    // (TestableCategorieController) surchargent cette factory pour
    // injecter un mock et éviter l'ouverture PDO déclenchée par
    // AbstractManager::__construct().
    // ==================================================================

    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }
    
    /**
    * [SÉCURITÉ] Nettoie le nom de fichier uploadé avant écriture disque.
    *
    * - basename() : retire tout chemin résiduel
    * - pathinfo(PATHINFO_FILENAME) : retire l'extension
    * - regex [^a-zA-Z0-9_-] : ne garde que l'alphanumérique ASCII + tirets
    * - mb_substr(..., 0, 100) : borne la longueur (limite FS safe)
    * - fallback 'image' : évite un nom vide (ex. '../../.jpg' → '')
    *
    * @param string $originalName Nom brut fourni par le client
    * @return string Fragment de nom de fichier utilisable (sans extension)
    */
    protected function sanitizeUploadedFilename(string $originalName): string
    {
        $name = pathinfo(basename($originalName), PATHINFO_FILENAME);
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        $name = mb_substr((string) $name, 0, 100);
        
        return $name === '' ? 'image' : $name;
    }

    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $categorieManager = $this->categorieManager();
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

        $categorieManager = $this->categorieManager();
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

        $categorieManager = $this->categorieManager();

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

            // [SÉCURITÉ] Validation + troncature des champs texte.
            // - libelle : obligatoire après trim(), max 32 (varchar(32))
            // - description : max DESCRIPTION_MAX_LENGTH (TEXT en BDD)
            // mb_substr() et non substr() pour ne pas couper un caractère
            // UTF-8 en deux (é = 2 octets).
            $libelleRaw = $request->request->get('libelle');
            $libelle = is_string($libelleRaw) ? trim($libelleRaw) : '';
            
            if ($libelle === '') {
                $form_errors['libelle'] = 'Le libellé est obligatoire.';
            } else {
                $libelle = mb_substr($libelle, 0, 32);
            }
            
            $descriptionRaw = $request->request->get('description');
            $description = is_string($descriptionRaw)
            ? mb_substr($descriptionRaw, 0, self::DESCRIPTION_MAX_LENGTH)
            : null;
            
            if (empty($form_errors)) {
                $categorie = array_merge(
                    $categorie,
                    [
                        'libelle' => $libelle,
                        'est_epi' => $request->request->has('est_epi') ? 1 : 0,
                        'description' => $description,
                    ]
                );
                
                if ($image = $request->files->get('image')) {
                    $sanitized = $this->sanitizeUploadedFilename($image->getClientOriginalName());
                    $newFilename = $sanitized . '-' . uniqid() . '.' . $image->guessExtension();
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

        $categorieManager = $this->categorieManager();
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