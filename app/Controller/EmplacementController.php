<?php

namespace Epiclub\Controller;

use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\ListeReferentiel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class EmplacementController extends AbstractController
{
    // ==================================================================
    // [REFACTOR VAGUE 11 — issue #64] Factory manager — mockable en
    // test Unit.
    //
    // Retourne une nouvelle instance concrète. Les sous-classes de test
    // (TestableEmplacementController) surchargent cette factory pour
    // injecter un mock et éviter l'ouverture PDO déclenchée par
    // AbstractManager::__construct().
    // ==================================================================

    protected function emplacementManager(): EmplacementManager
    {
        return new EmplacementManager();
    }

    /** [2026-10-25] Équipements — factory surchargeable en test. */
    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    /**
     * [2026-10-25] Nombre d'équipements (non supprimés) par emplacement :
     * [id => nombre] ; [] si le comptage est indisponible.
     *
     * @return array<int, int>
     */
    private function nbEquipements(): array
    {
        try {
            return $this->equipementManager()->compterPar('emplacement_id');
        } catch (\Throwable $e) {
            error_log('[' . static::class . '] nbEquipements : ' . $e->getMessage());
            return [];
        }
    }

    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $emplacementManager = $this->emplacementManager();
        $tous = $emplacementManager->findAll() ?: [];
        $nb = $this->nbEquipements();

        // [2026-10-25] Recherche, filtre (équipements) et tri
        $params = ListeReferentiel::parametres($request, ['libelle', 'equipements'], 'libelle');
        $emplacements = ListeReferentiel::rechercher($tous, $params['q'], ['libelle', 'description']);
        $emplacements = ListeReferentiel::filtrerEquipements($emplacements, $params['equipements'], $nb);
        $emplacements = ListeReferentiel::trier($emplacements, $params['order_by'], $params['order_dir'], [
            'libelle' => 'libelle',
            'equipements' => fn (array $e) => $nb[(int) $e['id']] ?? 0,
        ], 'libelle');

        return $this->render('emplacement_list.twig', [
            'emplacements' => $emplacements,
            'total' => count($tous),
            'filtres' => $params,
            // [2026-10-25] Lien vers les équipements de chaque emplacement
            'nb_equipements' => $nb,
        ]);
    }

    public function show(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/emplacements');
        }

        $emplacementManager = $this->emplacementManager();
        $emplacement = $emplacementManager->findId($id);

        if (!$emplacement) {
            $this->session->getFlashBag()->add('error', "L'emplacement demandé n'existe pas.");
            return new RedirectResponse('/admin/emplacements');
        }

        return $this->render('emplacement_show.twig', [
            'emplacement' => $emplacement,
            'nb_equipements' => $this->nbEquipements()[$id] ?? 0,
        ]);
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $emplacementManager = $this->emplacementManager();
        
        $emplacement = [];
        $form_errors = [];
        
        // --- Récupération de l'ID depuis la requête (GET ou POST) ---
        $id = $this->getValidId($request);
        if ($id !== null) {
            $emplacement = $emplacementManager->findId($id);
            if (!$emplacement) {
                // ID syntaxiquement valide mais inexistant en BDD
                $this->session->getFlashBag()->add('error', "L'emplacement demandé n'existe pas.");
                return new RedirectResponse('/admin/emplacements');
            }
        }
        
        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement
            $this->validateCsrf($request);
            
            // [SÉCURITÉ #53] Troncature défensive (libelle : varchar(64)).
            $libelle     = mb_substr(trim($request->request->get('libelle')), 0, 64);
            $description = trim($request->request->get('description'));
            // Champ « Image (URL) » : simple chaîne, aucun fichier téléversé
            $image = trim($request->request->get('image'));
            
            if (empty($libelle)) {
                $form_errors['libelle'] = 'Le libellé est obligatoire.';
            }
            
            // [VALIDATION] Si une URL est fournie, elle doit être valide
            // et utiliser le schéma http ou https (rejette javascript:, data:, etc.)
            if ($image !== '') {
                if (!filter_var($image, FILTER_VALIDATE_URL)) {
                    $form_errors['image'] = "L'URL de l'image n'est pas valide.";
                } else {
                    $scheme = strtolower((string) parse_url($image, PHP_URL_SCHEME));
                    if (!in_array($scheme, ['http', 'https'], true)) {
                        $form_errors['image'] = "L'URL de l'image doit commencer par http:// ou https://";
                    }
                }
            }
            
            if (empty($form_errors)) {
                $emplacement = array_merge(
                    $emplacement,
                    [
                        'libelle' => $libelle,
                        'description' => $description,
                        'image' => $image !== '' ? $image : null,
                    ]
                );
                
                $emplacementManager->save($emplacement);
                $this->session->getFlashBag()->add('success', "L'emplacement a été enregistré.");
                return $this->redirectTo("/admin/emplacements");
            }
        }
        
        return $this->render('emplacement_form.twig', [
            'emplacement' => $emplacement,
            'form_errors' => $form_errors
        ]);
    }

    public function delete(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        // [SÉCURITÉ] Une action destructive ne doit jamais être déclenchable par GET
        if (!$request->isMethod('POST')) {
            return $this->redirectTo('/admin/emplacements');
        }

        // [SÉCURITÉ] Vérification CSRF
        $this->validateCsrf($request);

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/emplacements');
        }

        $emplacementManager = $this->emplacementManager();
        $emplacement = $emplacementManager->findId($id);

        if (!$emplacement) {
            $this->session->getFlashBag()->add('error', "L'emplacement demandé n'existe pas.");
            return new RedirectResponse('/admin/emplacements');
        }

        if ($emplacementManager->hasEquipements($id)) {
            $this->session->getFlashBag()->add('error', "Impossible de supprimer cet emplacement car il contient des équipements.");
            return new RedirectResponse('/admin/emplacements');
        }

        // [MÉTIER] Hard delete en BDD. Aucun fichier à supprimer ici :
        // le champ `image` contient une URL externe, pas un chemin local.
        $emplacementManager->delete($id);

        $this->session->getFlashBag()->add('success', "L'emplacement '{$emplacement['libelle']}' a été supprimé.");

        return new RedirectResponse('/admin/emplacements');
    }
}