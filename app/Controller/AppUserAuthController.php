<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Epiclub\Engine\AppUserAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AppUserAuthController extends AbstractController
{
    public function login(Request $request)
    {
        $error_autentification = null;
        $form_errors = [];

        if ($request->getMethod() === 'POST') {

            if ($request->request->get('csrf_token') !== $this->session->get('csrf_token')) {
                throw new \Exception('Erreur de sécurité: Token CSRF invalide');
            }

            if (!$request->request->has('username')) {
                $form_errors['username'] = "Le nom d'utilisateur est requis.";
            }

            if (!$request->request->has('password')) {
                $form_errors['password'] = "Le mot de passe est requis.";
            }

            if (empty($form_errors)) {
                $appUserAuthenticator = new AppUserAuthenticator($this->session);

                if ($appUserAuthenticator->authenticate($request->request->get('username'), $request->request->get('password'))) {
                    $response = new RedirectResponse('.');
                    $response->send();
                }

                $error_autentification = $appUserAuthenticator->getError();
            }
        }

        return $this->render('user_login.twig', [
            'error_autentification' => $error_autentification,
            'form_errors' => $form_errors
        ]);
    }

    public function logout()
    {
        $this->session->clear();

        $response = new RedirectResponse('/');
        $response->send();
    }
}
