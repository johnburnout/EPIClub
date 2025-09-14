<?php

namespace Epiclub\Controller;

use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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

    public function delete(Request $request) {}
}
