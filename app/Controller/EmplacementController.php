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
        $this->deniAccessUnlessGranted('ROLE_USER');

        $emplacementManager = new EmplacementManager();
        $emplacements = $emplacementManager->findAll();

        return $this->render('emplacement_list.twig', [
            'emplacements' => $emplacements
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

        $emplacementManager = new EmplacementManager();
        $emplacement = $emplacementManager->findId($id);

        if (!$emplacement) {
            $this->session->getFlashBag()->add('error', "L'emplacement demandé n'existe pas.");
            return new RedirectResponse('/admin/emplacements');
        }

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
            $libelle = trim($request->request->get('libelle'));
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

        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return new RedirectResponse('/admin/emplacements');
        }

        $emplacementManager = new EmplacementManager();
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