<?php

namespace Epiclub\Controller;

use Epiclub\Domain\EmplacementManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class EmplacementController extends AbstractController
{
    public function list(Request $request)
    {
        $emplacementManager = new EmplacementManager();
        $emplacements = $emplacementManager->findAll();

        return $this->render('emplacement_list.twig', [
            'emplacements' => $emplacements
        ]);
    }

    public function show(Request $request)
    {
        $emplacementManager = new EmplacementManager();
        $emplacement = $emplacementManager->findId($request->get('id'));

        return $this->render('emplacement_show.twig', [
            'emplacement' => $emplacement
        ]);
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $emplacementManager = new EmplacementManager();

        $emplacement = [];
        $form_errors = [];

        if ($id = $request->get('id')) {
            $emplacement = $emplacementManager->findId($id);
        }

        if ($request->getMethod() === 'POST') {
            $libelle = trim($request->request->get('libelle'));
            $description = trim($request->request->get('description'));
            $image = $request->request->get('image');

            if (empty($libelle)) {
                $form_errors['libelle'] = 'Le libellé est obligatoire.';
            }

            if (empty($form_errors)) {
                $emplacement = array_merge(
                    $emplacement,
                    [
                        'libelle' => $libelle,
                        'description' => $description,
                        'image' => $image,
                    ]
                );

                $emplacementManager->save($emplacement);
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

        $id = $request->query->get('id');
        if (!$id) {
            $this->session->getFlashBag()->add('error', 'ID emplacement manquant.');
            return new RedirectResponse("/admin/emplacements");
        }

        $emplacementManager = new EmplacementManager();
        $emplacement = $emplacementManager->findId($id);

        if (!$emplacement) {
            $this->session->getFlashBag()->add('error', "L'emplacement demandé n'existe pas.");
            return new RedirectResponse("/admin/emplacements");
        }

        if ($emplacementManager->hasEquipements($id)) {
            $this->session->getFlashBag()->add('error', "Impossible de supprimer cet emplacement car il contient des équipements.");
            return new RedirectResponse("/admin/emplacements");
        }

        $emplacementManager->delete($id);

        $this->session->getFlashBag()->add('success', "L'emplacement '{$emplacement['libelle']}' a été supprimé.");

        return new RedirectResponse("/admin/emplacements");
    }
}