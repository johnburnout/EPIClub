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
        // ✅ Ne régénérer que si le token n'existe pas en session
        if (!$this->session->has('csrf_token')) {
            $this->session->set('csrf_token', bin2hex(random_bytes(32)));
        }

        $error_autentification = null;
        $form_errors = [];
        $csrf_error = false;

        if ($request->getMethod() === 'POST') {

            // ✅ Vérification du token CSRF
            $submittedToken = $request->request->get('csrf_token');
            $sessionToken = $this->session->get('csrf_token');

            if (!$submittedToken || $submittedToken !== $sessionToken) {
                // Token invalide : on régénère un nouveau token et on affiche une erreur
                $this->session->set('csrf_token', bin2hex(random_bytes(32)));
                $csrf_error = true;
                $error_autentification = 'Erreur de sécurité : Token CSRF invalide. Veuillez réessayer.';
            }

            if (!$csrf_error) {
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
        }

        return $this->render('user_login.twig', [
            'error_autentification' => $error_autentification,
            'form_errors' => $form_errors
        ]);
    }

    public function logout(Request $request)
    {
        // ✅ On ne vérifie pas le token CSRF pour la déconnexion
        // Si un token est envoyé, on le vérifie mais sans bloquer
        if ($request->getMethod() === 'POST') {
            $token = $request->request->get('csrf_token');
            if ($token && $token !== $this->session->get('csrf_token')) {
                error_log("⚠️ Déconnexion avec token CSRF invalide - IP: " . $_SERVER['REMOTE_ADDR']);
            }
        }

        // ✅ Nettoyer la session proprement
        $this->session->clear();

        // ✅ Forcer la régénération de l'ID de session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $response = new RedirectResponse('/');
        $response->send();
    }
}