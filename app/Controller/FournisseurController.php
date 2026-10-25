<?php

namespace Epiclub\Controller;

use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\ListeReferentiel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class FournisseurController extends AbstractController
{
    // ==================================================================
    // [REFACTOR VAGUE 11 — issue #65] Factory manager — mockable en
    // test Unit.
    //
    // Retourne une nouvelle instance concrète. Les sous-classes de test
    // (TestableFournisseurController) surchargent cette factory pour
    // injecter un mock et éviter l'ouverture PDO déclenchée par
    // AbstractManager::__construct().
    // ==================================================================

    protected function fournisseurManager(): FournisseurManager
    {
        return new FournisseurManager();
    }

    /** [2026-10-25] Équipements — factory surchargeable en test. */
    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    /**
     * [2026-10-25] Nombre d'équipements (non supprimés) par fournisseur :
     * [id => nombre] ; [] si le comptage est indisponible.
     *
     * @return array<int, int>
     */
    private function nbEquipements(): array
    {
        try {
            return $this->equipementManager()->compterParFournisseur();
        } catch (\Throwable $e) {
            error_log('[' . static::class . '] nbEquipements : ' . $e->getMessage());
            return [];
        }
    }

    /**
     * [2026-10-25] Consultable par tout utilisateur connecté (lecture
     * seule) ; création, modification et suppression : administrateurs.
     */
    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $fournisseurManager = $this->fournisseurManager();
        $tous = $fournisseurManager->findAll() ?: [];
        $nb = $this->nbEquipements();

        // [2026-10-25] Recherche, filtre (équipements) et tri
        $params = ListeReferentiel::parametres($request, ['nom', 'equipements'], 'nom');
        $fournisseurs = ListeReferentiel::rechercher($tous, $params['q'], ['nom', 'email', 'phone']);
        $fournisseurs = ListeReferentiel::filtrerEquipements($fournisseurs, $params['equipements'], $nb);
        $fournisseurs = ListeReferentiel::trier($fournisseurs, $params['order_by'], $params['order_dir'], [
            'nom' => 'nom',
            'equipements' => fn (array $f) => $nb[(int) $f['id']] ?? 0,
        ], 'nom');

        return $this->render('fournisseur_list.twig', [
            'fournisseurs' => $fournisseurs,
            'total' => count($tous),
            'filtres' => $params,
            'nb_equipements' => $nb,
        ]);
    }

    public function show(Request $request)
    {
        // [2026-10-25] Cohérent avec list() : consultable par tout utilisateur connecté
        $this->deniAccessUnlessGranted('ROLE_USER');

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/fournisseurs');
        }

        $fournisseurManager = $this->fournisseurManager();
        $fournisseur = $fournisseurManager->findId($id);

        if (!$fournisseur) {
            $this->session->getFlashBag()->add('error', "Le fournisseur demandé n'existe pas.");
            return new RedirectResponse('/admin/fournisseurs');
        }

        return $this->render('fournisseur_show.twig', [
            'fournisseur' => $fournisseur,
            'nb_equipements' => $this->nbEquipements()[$id] ?? 0,
        ]);
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $fournisseurManager = $this->fournisseurManager();

        $fournisseur = [];
        $form_errors = [];

        // --- Récupération de l'ID depuis la requête (GET ou POST) ---
        $id = $this->getValidId($request);
        if ($id !== null) {
            $fournisseur = $fournisseurManager->findId($id);
            if (!$fournisseur) {
                // ID syntaxiquement valide mais inexistant en BDD
                $this->session->getFlashBag()->add('error', "Le fournisseur demandé n'existe pas.");
                return new RedirectResponse('/admin/fournisseurs');
            }
        }

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement
            $this->validateCsrf($request);

            // [SÉCURITÉ #53] Troncature défensive (nom : varchar(64)).
            $nom   = mb_substr(trim($request->request->get('nom')), 0, 64);
            $email = trim($request->request->get('email'));
            $phone = trim($request->request->get('phone'));

            if (empty($nom)) {
                $form_errors['nom'] = 'Le nom est obligatoire.';
            }

            // Validation souple : l'email est optionnel mais doit être valide s'il est fourni
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_errors['email'] = "L'email n'est pas valide.";
            }

            if (empty($form_errors)) {
                $fournisseur = array_merge(
                    $fournisseur,
                    [
                        'nom' => $nom,
                        'email' => $email !== '' ? $email : null,
                        'phone' => $phone !== '' ? $phone : null,
                    ]
                );

                $fournisseurManager->save($fournisseur);
                $this->session->getFlashBag()->add('success', "Le fournisseur a été enregistré.");
                return $this->redirectTo("/admin/fournisseurs");
            }
        }

        return $this->render('fournisseur_form.twig', [
            'fournisseur' => $fournisseur,
            'form_errors' => $form_errors
        ]);
    }

    public function delete(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        // [SÉCURITÉ] Action destructive réservée à POST
        if (!$request->isMethod('POST')) {
            return new RedirectResponse('/admin/fournisseurs');
        }

        // [SÉCURITÉ] Vérification CSRF
        $this->validateCsrf($request);

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/fournisseurs');
        }

        $fournisseurManager = $this->fournisseurManager();
        $fournisseur = $fournisseurManager->findId($id);

        if (!$fournisseur) {
            $this->session->getFlashBag()->add('error', "Le fournisseur demandé n'existe pas.");
            return new RedirectResponse('/admin/fournisseurs');
        }

        // Vérifier si le fournisseur a des acquisitions associées
        if ($fournisseurManager->hasAcquisitions($id)) {
            $this->session->getFlashBag()->add('error', "Impossible de supprimer ce fournisseur car il a des acquisitions associées.");
            return new RedirectResponse('/admin/fournisseurs');
        }

        $fournisseurManager->delete($id);

        $this->session->getFlashBag()->add('success', "Le fournisseur '{$fournisseur['nom']}' a été supprimé.");

        return new RedirectResponse('/admin/fournisseurs');
    }
}