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
        $this->utilisateurManager = $this->utilisateurManager();
    }

    /**
     * Factory du manager — surchargée en test Unit pour injecter un
     * UtilisateurManager adossé à un PDO mocké (évite la vraie
     * connexion ouverte par AbstractManager::__construct()).
     */
    protected function utilisateurManager(): UtilisateurManager
    {
        return new UtilisateurManager();
    }

    /**
     * [SÉCURITÉ — audit P34] Colonnes sensibles JAMAIS stockées en
     * session : un vol de session (ou une itération non échappée dans
     * un template) ne doit pas exposer le hash du mot de passe ni les
     * tokens de réinitialisation.
     */
    private const SESSION_EXCLUDED_FIELDS = [
        'password',
        'reset_token',
        'reset_token_expires',
        'reset_email_sent_at',
    ];

    public function authenticate(string $username, string $plainTextPassword)
    {
        $user = $this->utilisateurManager->findOneByCriteria(['username' => $username]);

        if ($user && password_verify($plainTextPassword, $user['password'])) {
            // [SÉCURITÉ — audit P34] La session ne reçoit que la
            // projection non sensible de l'utilisateur.
            $sessionUser = array_diff_key(
                $user,
                array_flip(self::SESSION_EXCLUDED_FIELDS)
            );
            $this->session->set('user', $sessionUser);

            // [SÉCURITÉ — audit C3/P22] save() ne peut plus réécrire
            // password/role (ALLOWED_FIELDS restreint) ; on ne transmet
            // de toute façon que la projection non sensible.
            $sessionUser['derniere_connexion'] = date('Y-m-d');
            $this->utilisateurManager->save($sessionUser);

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
