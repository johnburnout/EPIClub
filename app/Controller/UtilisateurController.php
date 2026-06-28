<?php

namespace Epiclub\Controller;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Enum\UserRole;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class UtilisateurController extends AbstractController
{
    public function list(Request $request)
    {
        $utilisateurManager = new UtilisateurManager();
        $utilisateurs = $utilisateurManager->findAll();

        foreach ($utilisateurs as $i => $utilisateur) {
            $utilisateurs[$i]['rolelabel'] = UserRole::fromRole($utilisateur['role']);
        }
        return $this->render('utilisateur_list.twig', [
            'utilisateurs' => $utilisateurs
        ]);
    }

    /**
     * @deprecated Use this->show()
     */
    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $utilisateurManager = new UtilisateurManager();
        $utilisateur = [];
        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            // Récupération des données
            $utilisateur['nom'] = trim($request->request->get('nom'));
            $utilisateur['prenom'] = trim($request->request->get('prenom'));
            $utilisateur['username'] = trim($request->request->get('username'));
            $utilisateur['email'] = trim($request->request->get('email'));
            $utilisateur['role'] = $request->request->get('role');
            $password = $request->request->get('password');
            
            // Validation
            if (empty($utilisateur['nom'])) $form_errors['nom'] = 'Le nom est obligatoire.';
            if (empty($utilisateur['prenom'])) $form_errors['prenom'] = 'Le prénom est obligatoire.';
            if (empty($utilisateur['username'])) $form_errors['username'] = "Le nom d'utilisateur est obligatoire.";
            if (empty($utilisateur['email'])) $form_errors['email'] = "L'email est obligatoire.";
            if (!filter_var($utilisateur['email'], FILTER_VALIDATE_EMAIL)) $form_errors['email'] = "L'email n'est pas valide.";
            if (empty($password)) $form_errors['password'] = 'Le mot de passe est obligatoire.';
            
            // Vérification de l'unicité
            if ($utilisateurManager->findOneByCriteria(['email' => $utilisateur['email']])) {
                $form_errors['email'] = "Cet email est déjà utilisé.";
            }
            if ($utilisateurManager->findOneByCriteria(['username' => $utilisateur['username']])) {
                $form_errors['username'] = "Ce nom d'utilisateur est déjà pris.";
            }
            
            if (empty($form_errors)) {
                $utilisateur['password'] = password_hash($password, PASSWORD_DEFAULT);
                $utilisateur['date_creation'] = date('Y-m-d H:i:s');
                $utilisateur['derniere_connexion'] = null;
                
                $utilisateurManager->save($utilisateur);
                // Message flash de succès
                return new RedirectResponse("/admin/utilisateurs");
            }
        }
        
        return $this->render('utilisateur_form.twig', [
            'utilisateur' => $utilisateur,
            'roles' => UserRole::list(),
            'form_errors' => $form_errors
        ]);
    }
    
    public function show(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $utilisateurManager = new UtilisateurManager();
        $utilisateur = $utilisateurManager->findId($request->get('id'));
        if (!$utilisateur) {
            return new RedirectResponse("/admin/utilisateurs");
        }
        
        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            // Récupération des données du formulaire
            $utilisateur['nom'] = trim($request->request->get('nom'));
            $utilisateur['prenom'] = trim($request->request->get('prenom'));
            $utilisateur['username'] = trim($request->request->get('username'));
            $utilisateur['email'] = trim($request->request->get('email'));
            $utilisateur['role'] = $request->request->get('role');
            $password = $request->request->get('password');
            
            // Validation
            if (empty($utilisateur['nom'])) $form_errors['nom'] = 'Le nom est obligatoire.';
            if (empty($utilisateur['prenom'])) $form_errors['prenom'] = 'Le prénom est obligatoire.';
            if (empty($utilisateur['username'])) $form_errors['username'] = "Le nom d'utilisateur est obligatoire.";
            if (empty($utilisateur['email'])) $form_errors['email'] = "L'email est obligatoire.";
            if (!filter_var($utilisateur['email'], FILTER_VALIDATE_EMAIL)) $form_errors['email'] = "L'email n'est pas valide.";
            
            // Vérification de l'unicité (email et username) - exclure l'utilisateur actuel
            if ($existingUser = $utilisateurManager->findOneByCriteria(['email' => $utilisateur['email']])) {
                if ($existingUser['id'] != $utilisateur['id']) {
                    $form_errors['email'] = "Cet email est déjà utilisé par un autre compte.";
                }
            }
            if ($existingUser = $utilisateurManager->findOneByCriteria(['username' => $utilisateur['username']])) {
                if ($existingUser['id'] != $utilisateur['id']) {
                    $form_errors['username'] = "Ce nom d'utilisateur est déjà pris.";
                }
            }
            
            // Gestion du mot de passe (optionnel en modification)
            if ($password) {
                $utilisateur['password'] = password_hash($password, PASSWORD_DEFAULT);
            } else {
                // On conserve l'ancien mot de passe
                $existingUser = $utilisateurManager->findId($utilisateur['id']);
                $utilisateur['password'] = $existingUser['password'];
            }
            
            if (empty($form_errors)) {
                $utilisateurManager->save($utilisateur);
                // Message flash de succès
                return new RedirectResponse("/admin/utilisateurs/utilisateur-$utilisateur[id]");
            }
        }
        
        return $this->render('utilisateur_show.twig', [
            'utilisateur' => $utilisateur,
            'roles' => UserRole::list(),
            'form_errors' => $form_errors
        ]);
    }

    /**
     * @deprecated A user/utilisateur can be deleted only by himself
     */
    public function delete(Request $request)
    {
        $response = $this->deniAccessUnlessGranted('ROLE_ADMIN');
        if ($response) {
            return $response;
        }
        
        $id = $request->get('id');
        if (!$id) {
            $this->session->getFlashBag()->add('error', 'ID utilisateur manquant.');
            return new RedirectResponse("/admin/utilisateurs");
        }
        
        $utilisateurManager = new UtilisateurManager();
        $utilisateur = $utilisateurManager->findId($id);
        
        if (!$utilisateur) {
            $this->session->getFlashBag()->add('error', "L'utilisateur demandé n'existe pas.");
            return new RedirectResponse("/admin/utilisateurs");
        }
        
        // Récupérer l'utilisateur connecté
        $currentUser = $this->session->get('user');
        if (!$currentUser) {
            // Si l'utilisateur n'est pas dans la session, essayez de le récupérer autrement
            // ou ignorez la vérification d'auto-suppression
            $this->session->getFlashBag()->add('error', 'Impossible de vérifier votre identité.');
            return new RedirectResponse("/admin/utilisateurs");
        }
        
        // Empêcher l'auto-suppression
        if ($id == $currentUser['id']) {
            $this->session->getFlashBag()->add('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            return new RedirectResponse("/admin/utilisateurs");
        }
        
        $utilisateurManager->delete($id);
        
        $this->session->getFlashBag()->add('success', "L'utilisateur {$utilisateur['prenom']} {$utilisateur['nom']} a été supprimé.");
        
        return new RedirectResponse("/admin/utilisateurs");
    }
}
