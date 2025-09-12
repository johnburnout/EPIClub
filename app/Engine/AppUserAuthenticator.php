<?php

namespace Epiclub\Engine;

use Epiclub\Engine\Session;
use Epiclub\Domain\UtilisateurManager;

class AppUserAuthenticator
{
    protected UtilisateurManager $utilisateurManager;
    private string $error = '';

    public function __construct(private Session $session)
    {
        $this->utilisateurManager = new UtilisateurManager();
    }

    public function authenticate(string $username, string $plainTextPassword)
    {
        $user = $this->utilisateurManager->findOneByCriteria(['username' => $username]);

        if ($user && password_verify($plainTextPassword, $user['password'])) {
            $this->session->set('user', $user);
            
            $user['derniere_connexion'] = date('Y-m-d');
            $this->utilisateurManager->save($user);

            $this->session->migrate();

            return true;
        }

        $this->error = "Identifiants incorrects";

        return false;
    }

    public function getError()
    {
        return $this->error;
    }
}
