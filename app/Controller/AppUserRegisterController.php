<?php

namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Engine\MailerService;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
                    $clubManager = new ClubManager();
                    $club = $clubManager->findParameters();
                    // build url
                    // set tocken in user table
                    // send email to retrive password
                    if (null !== $club['email']) {
                        $email = $this->createEmail($club['email'], $utilisateur['email'], 'Changement de mot de passe', 'email/reset_password.twig', ['club' => $club, 'user' => $utilisateur]);
                        $mailerService = new MailerService();
                        $mailerService->sendEmail($email);
                    }
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
