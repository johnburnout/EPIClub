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

        /* if ($request->isXmlHttpRequest()) {
            $fournisseur =  json_decode($request->getContent(), true);
            $fournisseurManager = new FournisseurManager();
            if ($id = $fournisseurManager->save($fournisseur)) {
                $fournisseur['id'] = $id;
                return new JsonResponse($fournisseur);
            }
            return new JsonResponse(['error' => true], 422);
        } */

        $fournisseur = [];

        if ($request->get('id')) {
            $fournisseurManager = new FournisseurManager();
            $fournisseur = $fournisseurManager->findId($request->get('id'));
        }

        if ($request->getMethod() === 'POST') {
            /** @todo need validation here */

            $fournisseur = array_merge($fournisseur, $request->request->all()['fournisseur']);

            if (empty($form_errors)) {
                $fournisseurManager->save($fournisseur);
                /** @todo flash success */
                return $this->redirectTo("/admin/fournisseurs");
            }

            /** @todo else error something wrong... */
        }

        return $this->render('fournisseur_form.twig', [
            'fournisseur' => $fournisseur
        ]);
    }

    public function delete(Request $request) {}
}
