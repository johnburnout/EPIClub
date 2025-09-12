<?php

namespace Epiclub\Controller;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class AppUserRegisterController extends AbstractController
{
    public function account(Request $request)
    {
        return $this->render('', []);
    }

    public function edit(Request $request)
    {
        return $this->render('', []);
    }

    public function forgotPassword(Request $request)
    {
        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            if (false == filter_var($request->request->get('email'), FILTER_VALIDATE_EMAIL)) {
                $form_errors['email'] = 'Veuillez entrer une adresse mail valide.';
            }

            if (empty($form_errors)) {
                $utilisateurManager = new UtilisateurManager();
                if ($utilisateur = $utilisateurManager->findOneByCriteria(['email' => $request->request->get('email')])) {
                    // build url
                    // set tocken in user table
                    // send email to retrive password
                }

                // set flash 'info', "La procédure de récupération à été envoyé à l'adresse mail indiquée. Verifier votre boite de réception et vos spams."

                return new RedirectResponse('/');
            }
        }

        return $this->render('user_forgot_password.twig', [
            'form_errors' => $form_errors
        ]);
    }

    public function resetPassword(Request $request)
    {
        return $this->render('', []);
    }
}
