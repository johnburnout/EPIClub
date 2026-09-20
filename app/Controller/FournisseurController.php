<?php

namespace Epiclub\Controller;

use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class FournisseurController extends AbstractController
{
    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $fournisseurManager = new FournisseurManager();
        $fournisseurs = $fournisseurManager->findAll();

        return $this->render('fournisseur_list.twig', [
            'fournisseurs' => $fournisseurs
        ]);
    }

    public function show(Request $request)
    {
        // Cohérent avec list() : les fiches fournisseurs sont réservées aux admins
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/fournisseurs');
        }

        $fournisseurManager = new FournisseurManager();
        $fournisseur = $fournisseurManager->findId($id);

        if (!$fournisseur) {
            $this->session->getFlashBag()->add('error', "Le fournisseur demandé n'existe pas.");
            return new RedirectResponse('/admin/fournisseurs');
        }

        return $this->render('fournisseur_show.twig', [
            'fournisseur' => $fournisseur
        ]);
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $fournisseurManager = new FournisseurManager();

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
            $nom = trim($request->request->get('nom'));
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

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/fournisseurs');
        }

        $fournisseurManager = new FournisseurManager();
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