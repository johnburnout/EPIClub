<?php

namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\ImageUploader;
use Epiclub\Engine\ListeReferentiel;
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
     * Factory ReferenceGenerator — mockable en test Unit (même pattern
     * que categorieManager()).
     */
    protected function referenceGenerator(): ReferenceGenerator
    {
        return new ReferenceGenerator();
    }

    /**
     * [SÉCURITÉ — audit 2026-10-19 S1] Dossier des images de catégorie
     * (servi par le serveur web). Factory surchargée en test Unit pour
     * écrire dans un dossier temporaire.
     */
    protected function imagesDir(): string
    {
        return __DIR__ . self::IMAGE_DIR;
    }

    /**
     * [SÉCURITÉ — audit 2026-10-19 S1] Upload sûr : liste blanche MIME +
     * réencodage GD (voir ImageUploader). Mockable en test Unit.
     */
    protected function imageUploader(): ImageUploader
    {
        return new ImageUploader($this->imagesDir());
    }

    /**
     * Codes déjà pris par les AUTRES catégories (pour la suggestion et
     * le contrôle d'unicité).
     *
     * @return string[]
     */
    private function codesPris(?int $excludeId): array
    {
        $codes = [];
        // « ?: [] » : findAll() peut retourner null sur un mock de test
        // non configuré (défensif, iso-comportement en production).
        foreach ($this->categorieManager()->findAll() ?: [] as $cat) {
            if ($excludeId !== null && (int) ($cat['id'] ?? 0) === $excludeId) {
                continue;
            }
            $code = $cat['code'] ?? null;
            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }
        return $codes;
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

    /** [2026-10-25] Équipements — factory surchargeable en test. */
    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    /**
     * [2026-10-25] Nombre d'équipements (non supprimés) par catégorie :
     * [id => nombre] ; [] si le comptage est indisponible.
     *
     * @return array<int, int>
     */
    private function nbEquipements(): array
    {
        try {
            return $this->equipementManager()->compterPar('categorie_id');
        } catch (\Throwable $e) {
            error_log('[' . static::class . '] nbEquipements : ' . $e->getMessage());
            return [];
        }
    }

    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $categorieManager = $this->categorieManager();
        $toutes = $categorieManager->findAll() ?: [];
        $nb = $this->nbEquipements();

        // [2026-10-25] Recherche, filtres (EPI, durée max, équipements) et tri
        $params = ListeReferentiel::parametres($request, ['libelle', 'code', 'equipements'], 'libelle', [
            'epi' => ['oui', 'non'],
            'duree_max' => ['oui', 'non'],
        ]);
        $categories = ListeReferentiel::rechercher($toutes, $params['q'], ['libelle', 'code', 'description']);
        if ($params['epi'] !== null) {
            $categories = array_filter($categories, fn ($c) => !empty($c['est_epi']) === ($params['epi'] === 'oui'));
        }
        if ($params['duree_max'] !== null) {
            $categories = array_filter($categories, fn ($c) => !empty($c['duree_max_utilisation']) === ($params['duree_max'] === 'oui'));
        }
        $categories = ListeReferentiel::filtrerEquipements($categories, $params['equipements'], $nb);
        $categories = ListeReferentiel::trier($categories, $params['order_by'], $params['order_dir'], [
            'libelle' => 'libelle',
            'code' => 'code',
            'equipements' => fn (array $c) => $nb[(int) $c['id']] ?? 0,
        ], 'libelle');

        return $this->render('categorie_list.twig', [
            'categories' => $categories,
            'total' => count($toutes),
            'filtres' => $params,
            // [2026-10-25] Lien vers les équipements de chaque catégorie
            'nb_equipements' => $nb,
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
            'categorie' => $categorie,
            'nb_equipements' => $this->nbEquipements()[$id] ?? 0,
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

            // [MÉTIER] Code catégorie (référencement automatique) :
            // normalisé (majuscules, accents retirés), 2 à 8 caractères
            // [A-Z0-9], unique hors soi. Vide → suggestion automatique.
            $referenceGenerator = $this->referenceGenerator();
            $codeRaw = $request->request->get('code');
            $code = is_string($codeRaw) ? $referenceGenerator->normalize(trim($codeRaw)) : '';
            $selfId = isset($categorie['id']) ? (int) $categorie['id'] : null;

            if ($code === '') {
                // Jamais d'erreur sur le libellé ici : il est déjà validé
                // plus haut (obligatoire).
                $code = $libelle !== ''
                    ? $referenceGenerator->suggestCode($libelle, $this->codesPris($selfId))
                    : null;
            } elseif (!preg_match('/^[A-Z0-9]{2,8}$/', $code)) {
                $form_errors['code'] = 'Le code doit contenir 2 à 8 caractères (lettres sans accent ou chiffres).';
            } elseif (in_array($code, $this->codesPris($selfId), true)) {
                $suggestion = $referenceGenerator->suggestCode($libelle !== '' ? $libelle : $code, $this->codesPris($selfId));
                $form_errors['code'] = "Ce code est déjà utilisé par une autre catégorie. Suggestion : $suggestion.";
            }

            if (empty($form_errors)) {
                $categorie = array_merge(
                    $categorie,
                    [
                        'libelle' => $libelle,
                        'est_epi' => $request->request->has('est_epi') ? 1 : 0,
                        'duree_max_utilisation' => $request->request->has('duree_max_utilisation') ? 1 : 0,
                        'textile_remplacable' => $request->request->has('textile_remplacable') ? 1 : 0,
                        'description' => $description,
                        'code' => $code,
                    ]
                );
                
                $image = $request->files->get('image');
                if ($image !== null) {
                    // [SÉCURITÉ — audit 2026-10-19 S1] Avant ce correctif,
                    // le fichier client était déplacé tel quel dans
                    // public/images/ avec l'extension devinée (un HTML ou
                    // un SVG piégé y devenait servi en .html / .svg :
                    // XSS stockée). Désormais : liste blanche + réencodage.
                    try {
                        $sanitized = $this->sanitizeUploadedFilename((string) $image->getClientOriginalName());
                        $newFilename = $this->imageUploader()->save($image, $sanitized);

                        // Supprimer l'ancienne image si on est en mise à jour
                        if (!empty($categorie['image']) && $categorie['image'] !== $newFilename) {
                            $oldImagePath = $this->imagesDir() . basename($categorie['image']);
                            if (is_file($oldImagePath)) {
                                @unlink($oldImagePath);
                            }
                        }

                        $categorie['image'] = $newFilename;
                    } catch (\InvalidArgumentException $e) {
                        $form_errors['image'] = $e->getMessage();
                    } catch (\Throwable $e) {
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

        // [SÉCURITÉ Dette 9] Garde du FOREIGN KEY acquisition_ligne.categorie_id
        if ($categorieManager->hasLignesAcquisition($id)) {
            $this->session->getFlashBag()->add('error', "Impossible de supprimer cette catégorie car elle est utilisée par des lignes d'acquisition.");
            return new RedirectResponse('/admin/categories');
        }

        // [MÉTIER] Hard delete => on supprime aussi le fichier image associé
        $imageToDelete = null;
        if (!empty($categorie['image'])) {
            $imageToDelete = $this->imagesDir() . basename($categorie['image']);
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