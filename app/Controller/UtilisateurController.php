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
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

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

        $actor = $this->session->get('user');
        $actorRole = $actor['role'] ?? 'ROLE_USER';
        $assignableRoles = UserRole::listAssignableBy($actorRole);

        $utilisateurManager = new UtilisateurManager();
        $utilisateur = [];
        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            // Récupération des données
            $utilisateur['nom'] = trim($request->request->get('nom'));
            $utilisateur['prenom'] = trim($request->request->get('prenom'));
            $utilisateur['username'] = trim($request->request->get('username'));
            $utilisateur['email'] = trim($request->request->get('email'));
            $submittedRole = $request->request->get('role');
            $password = $request->request->get('password');

            // Validation
            if (empty($utilisateur['nom'])) $form_errors['nom'] = 'Le nom est obligatoire.';
            if (empty($utilisateur['prenom'])) $form_errors['prenom'] = 'Le prénom est obligatoire.';
            if (empty($utilisateur['username'])) $form_errors['username'] = "Le nom d'utilisateur est obligatoire.";
            if (empty($utilisateur['email'])) $form_errors['email'] = "L'email est obligatoire.";
            if (!filter_var($utilisateur['email'], FILTER_VALIDATE_EMAIL)) $form_errors['email'] = "L'email n'est pas valide.";
            if (empty($password)) $form_errors['password'] = 'Le mot de passe est obligatoire.';

            // [SÉCURITÉ] Le rôle soumis doit être dans la liste autorisée pour l'acteur
            if (!array_key_exists($submittedRole, $assignableRoles)) {
                $form_errors['role'] = "Vous n'êtes pas autorisé à attribuer ce rôle.";
            } else {
                $utilisateur['role'] = $submittedRole;
            }

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
                $this->session->getFlashBag()->add('success', "L'utilisateur a été créé.");
                return new RedirectResponse("/admin/utilisateurs");
            }
        }

        return $this->render('utilisateur_form.twig', [
            'utilisateur' => $utilisateur,
            'roles' => $assignableRoles,
            'form_errors' => $form_errors
        ]);
    }

    public function show(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $id = $this->getValidId($request);
        if ($id === null) {
            return new RedirectResponse("/admin/utilisateurs");
        }
        
        $utilisateurManager = new UtilisateurManager();
        $utilisateur = $utilisateurManager->findId($id);
        
        if (!$utilisateur) {
            return new RedirectResponse("/admin/utilisateurs");
        }
        
        $actor = $this->session->get('user');
        $actorRole = $actor['role'] ?? 'ROLE_USER';
        $isSelf = ($actor['id'] == $utilisateur['id']);
        
        // [SÉCURITÉ] Vérifier que l'acteur a le droit de gérer cette cible
        if (!$this->canManageUser($actor, $utilisateur)) {
            $this->session->getFlashBag()->add('error', "Vous n'avez pas les droits pour modifier cet utilisateur.");
            return new RedirectResponse("/admin/utilisateurs");
        }
        
        $assignableRoles = UserRole::listAssignableBy($actorRole);
        
        // Pour l'affichage uniquement : si on modifie son propre compte, on veut
        // voir son rôle actuel dans la liste, même s'il n'est pas attribuable par soi-même.
        if ($isSelf && !array_key_exists($utilisateur['role'], $assignableRoles)) {
            $assignableRoles[$utilisateur['role']] = UserRole::fromRole($utilisateur['role']);
        }
        
        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            // Récupération des données du formulaire
            $utilisateur['nom'] = trim($request->request->get('nom'));
            $utilisateur['prenom'] = trim($request->request->get('prenom'));
            $utilisateur['username'] = trim($request->request->get('username'));
            $utilisateur['email'] = trim($request->request->get('email'));
            $submittedRole = $request->request->get('role');
            $password = $request->request->get('password');
            
            // Validation
            if (empty($utilisateur['nom'])) $form_errors['nom'] = 'Le nom est obligatoire.';
            if (empty($utilisateur['prenom'])) $form_errors['prenom'] = 'Le prénom est obligatoire.';
            if (empty($utilisateur['username'])) $form_errors['username'] = "Le nom d'utilisateur est obligatoire.";
            if (empty($utilisateur['email'])) $form_errors['email'] = "L'email est obligatoire.";
            if (!filter_var($utilisateur['email'], FILTER_VALIDATE_EMAIL)) $form_errors['email'] = "L'email n'est pas valide.";
            
            // Vérification de l'unicité - exclure l'utilisateur actuel
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
            
            // [SÉCURITÉ] Gestion du rôle
            if ($isSelf) {
                // Un utilisateur ne peut pas modifier son propre rôle via ce formulaire.
                // Seul un autre administrateur (SUPER_ADMIN) peut le faire.
                // Le rôle courant est donc conservé tel quel.
                // (Aucune affectation, on garde $utilisateur['role'] chargé depuis la BDD.)
            } else {
                if (!array_key_exists($submittedRole, $assignableRoles)) {
                    $form_errors['role'] = "Vous n'êtes pas autorisé à attribuer ce rôle.";
                } else {
                    // [SÉCURITÉ] Empêcher la rétrogradation du dernier SUPER_ADMIN
                    $isDemotingSuperAdmin = ($utilisateur['role'] === 'ROLE_SUPER_ADMIN' && $submittedRole !== 'ROLE_SUPER_ADMIN');
                    if ($isDemotingSuperAdmin && $this->isLastSuperAdmin($utilisateur['id'])) {
                        $form_errors['role'] = "Impossible de rétrograder le dernier SUPER_ADMIN de l'application.";
                    } else {
                        $utilisateur['role'] = $submittedRole;
                    }
                }
            }
            
            // Gestion du mot de passe (optionnel en modification)
            if ($password) {
                $utilisateur['password'] = password_hash($password, PASSWORD_DEFAULT);
            } else {
                $existingUser = $utilisateurManager->findId($utilisateur['id']);
                $utilisateur['password'] = $existingUser['password'];
            }
            
            if (empty($form_errors)) {
                $utilisateurManager->save($utilisateur);
                $this->session->getFlashBag()->add('success', "L'utilisateur a été mis à jour.");
                return new RedirectResponse("/admin/utilisateurs/utilisateur-$utilisateur[id]");
            }
        }
        
        // [SÉCURITÉ] Le bouton Supprimer n'est affiché que si la suppression est
        // réellement possible. Le contrôleur reste la source de vérité ; le
        // template ne fait que refléter ce calcul.
        //
        // Trois cas bloquants :
        //   1. C'est soi-même (auto-suppression interdite)
        //   2. C'est le compte 'admin' (protection historique)
        //   3. C'est le dernier SUPER_ADMIN (bloquerait l'accès aux réglages critiques)
        $canDelete = !$isSelf
        && $utilisateur['username'] !== 'admin'
        && !($utilisateur['role'] === 'ROLE_SUPER_ADMIN' && $this->isLastSuperAdmin($utilisateur['id']));
        
        return $this->render('utilisateur_show.twig', [
            'utilisateur' => $utilisateur,
            'roles' => $assignableRoles,
            'is_self' => $isSelf,
            'can_delete' => $canDelete,
            'form_errors' => $form_errors
        ]);
    }

    /**
     * @deprecated A user/utilisateur can be deleted only by himself
     */
    public function delete(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $id = $this->getValidId($request);
        if ($id === null) {
            return new RedirectResponse("/admin/utilisateurs");
        }

        $utilisateurManager = new UtilisateurManager();
        $utilisateur = $utilisateurManager->findId($id);

        if (!$utilisateur) {
            $this->session->getFlashBag()->add('error', "L'utilisateur demandé n'existe pas.");
            return new RedirectResponse("/admin/utilisateurs");
        }

        $currentUser = $this->session->get('user');
        if (!$currentUser) {
            $this->session->getFlashBag()->add('error', 'Impossible de vérifier votre identité.');
            return new RedirectResponse("/admin/utilisateurs");
        }

        // [SÉCURITÉ] Vérifier que l'acteur a le droit de gérer cette cible
        if (!$this->canManageUser($currentUser, $utilisateur)) {
            $this->session->getFlashBag()->add('error', "Vous n'avez pas les droits pour supprimer cet utilisateur.");
            return new RedirectResponse("/admin/utilisateurs");
        }

        // Empêcher l'auto-suppression
        if ($id == $currentUser['id']) {
            $this->session->getFlashBag()->add('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            return new RedirectResponse("/admin/utilisateurs");
        }

        // 🔒 PROTECTION DU SUPER ADMINISTRATEUR "admin" (protection historique)
        if ($utilisateur['username'] === 'admin') {
            $this->session->getFlashBag()->add('error', "❌ Le super administrateur 'admin' ne peut pas être supprimé.");
            return new RedirectResponse("/admin/utilisateurs");
        }

        // [SÉCURITÉ] Empêcher la suppression du dernier SUPER_ADMIN
        if ($utilisateur['role'] === 'ROLE_SUPER_ADMIN' && $this->isLastSuperAdmin($utilisateur['id'])) {
            $this->session->getFlashBag()->add('error', "Impossible de supprimer le dernier SUPER_ADMIN de l'application.");
            return new RedirectResponse("/admin/utilisateurs");
        }

        $utilisateurManager->delete($id);

        $this->session->getFlashBag()->add('success', "L'utilisateur {$utilisateur['prenom']} {$utilisateur['nom']} a été supprimé.");

        return new RedirectResponse("/admin/utilisateurs");
    }

    /**
     * [SÉCURITÉ] Vérifie que l'acteur a le droit de gérer la cible.
     *
     * Règles :
     *   - SUPER_ADMIN peut gérer tout le monde
     *   - ADMIN peut gérer USER et CONTROLLEUR, ainsi que lui-même
     *   - Les autres ne peuvent rien gérer
     *
     * @param array $actor  L'utilisateur qui agit (depuis la session)
     * @param array $target L'utilisateur cible de l'action
     */
    private function canManageUser(array $actor, array $target): bool
    {
        $actorRole = $actor['role'] ?? 'ROLE_USER';
        $targetRole = $target['role'] ?? 'ROLE_USER';

        // SUPER_ADMIN peut tout gérer
        if ($actorRole === 'ROLE_SUPER_ADMIN') {
            return true;
        }

        // ADMIN : lui-même + USER + CONTROLLEUR
        if ($actorRole === 'ROLE_ADMIN') {
            if ($actor['id'] == $target['id']) {
                return true;
            }
            return in_array($targetRole, ['ROLE_USER', 'ROLE_CONTROLLEUR'], true);
        }

        return false;
    }

    /**
     * [SÉCURITÉ] Indique s'il s'agit du dernier SUPER_ADMIN de l'application.
     *
     * Empêche qu'un SUPER_ADMIN se rétrograde ou se supprime s'il est le seul,
     * ce qui bloquerait définitivement l'accès aux réglages critiques
     * (club, SMTP, mise à jour).
     *
     * Note perf : findAll() charge tous les utilisateurs. Acceptable à
     * l'échelle d'un club. Optimisable plus tard avec un COUNT dédié
     * dans UtilisateurManager.
     *
     * @param int $userId L'ID de l'utilisateur ciblé
     */
    private function isLastSuperAdmin(int $userId): bool
    {
        $utilisateurManager = new UtilisateurManager();
        $allUsers = $utilisateurManager->findAll();

        $superAdmins = array_filter($allUsers, function ($u) {
            return ($u['role'] ?? null) === 'ROLE_SUPER_ADMIN';
        });

        // Au moins 2 SUPER_ADMIN → la rétrogradation/suppression est possible
        if (count($superAdmins) > 1) {
            return false;
        }

        // Un seul SUPER_ADMIN : on vérifie que c'est bien la cible
        $lastSuperAdmin = reset($superAdmins);
        return $lastSuperAdmin && $lastSuperAdmin['id'] == $userId;
    }
}