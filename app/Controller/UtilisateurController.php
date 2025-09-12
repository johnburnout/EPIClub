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

    public function show(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $utilisateurManager = new UtilisateurManager();
        $utilisateur = $utilisateurManager->findId($request->get('id'));
        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            /** @todo need validation here */

            if (empty($form_errors)) {
                $utilisateur['role'] = $request->request->get('role');
                $utilisateurManager->save($utilisateur);

                // put flash
                return new RedirectResponse("/admin/utilisateurs/utilisateur-$utilisateur[id]");
            }
            /** @todo else error something wrong... */
        }

        return $this->render('utilisateur_show.twig', [
            'utilisateur' => $utilisateur,
            'roles' => UserRole::list(),
            'form_errors' => $form_errors
        ]);
    }

    /**
     * @deprecated Use this->show()
     */
    /* public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $utilisateur = [];

        if ($request->get('id')) {
            $utilisateurManager = new UtilisateurManager();
            $utilisateur = $utilisateurManager->findId($request->get('id'));
        }

        return $this->render('utilisateur_form.twig', [
            'utilisateur' => $utilisateur
        ]);
    } */

    /**
     * @deprecated
     */
    public function delete(Request $request) {}
}
