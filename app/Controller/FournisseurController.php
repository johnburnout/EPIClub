<?php

namespace Epiclub\Controller;

use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse; // ← IMPORTANT

class FournisseurController extends AbstractController
{
    public function list(Request $request)
    {
        $fournisseurManager = new FournisseurManager();
        $fournisseurs = $fournisseurManager->findAll();

        return $this->render('fournisseur_list.twig', [
            'fournisseurs' => $fournisseurs
        ]);
    }

    public function show(Request $request)
    {
        $fournisseurManager = new FournisseurManager();
        $fournisseur = $fournisseurManager->findId($request->get('id'));

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

        if ($id = $request->get('id')) {
            $fournisseur = $fournisseurManager->findId($id);
        }

        if ($request->getMethod() === 'POST') {
            /** @todo need validation here */

            if (empty($form_errors)) {
                $fournisseur = array_merge(
                    $fournisseur,
                    [
                        'nom' => $request->request->get('nom'),
                        'email' => $request->request->get('email'),
                        'phone' => $request->request->get('phone'),
                    ]
                );
                $fournisseurManager->save($fournisseur);
                /** @todo flash success */
                return $this->redirectTo("/admin/fournisseurs");
            }

            /** @todo else error something wrong... */
        }

        return $this->render('fournisseur_form.twig', [
            'fournisseur' => $fournisseur,
            'form_errors' => $form_errors
        ]);
    }

    public function delete(Request $request)
    {
        $id = $request->query->get('id');
        if (!$id) {
            $this->session->getFlashBag()->add('error', 'ID fournisseur manquant.');
            return new RedirectResponse("/admin/fournisseurs");
        }
        
        $fournisseurManager = new FournisseurManager();
        $fournisseur = $fournisseurManager->findId($id);
        
        if (!$fournisseur) {
            $this->session->getFlashBag()->add('error', "Le fournisseur demandé n'existe pas.");
            return new RedirectResponse("/admin/fournisseurs");
        }
        
        // Vérifier si le fournisseur a des acquisitions associées
        if ($fournisseurManager->hasAcquisitions($id)) {
            $this->session->getFlashBag()->add('error', "Impossible de supprimer ce fournisseur car il a des acquisitions associées.");
            return new RedirectResponse("/admin/fournisseurs");
        }
        
        $fournisseurManager->delete($id);
        
        $this->session->getFlashBag()->add('success', "Le fournisseur '{$fournisseur['nom']}' a été supprimé.");
        
        return new RedirectResponse("/admin/fournisseurs");
    }
}