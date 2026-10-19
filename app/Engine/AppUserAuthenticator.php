<?php

namespace Epiclub\Engine;

use Epiclub\Engine\Session;
use Epiclub\Domain\LoginThrottleManager;
use Epiclub\Domain\UtilisateurManager;

class AppUserAuthenticator
{
    protected UtilisateurManager $utilisateurManager;
    private string $error = '';
    private ?LoginThrottleManager $loginThrottle = null;

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
     * [SÉCURITÉ — audit 2026-10-19 S2] Factory du limiteur de tentatives
     * — surchargée en test Unit (stockage en mémoire).
     */
    protected function loginThrottle(): LoginThrottleManager
    {
        return new LoginThrottleManager();
    }

    /** Échecs tolérés par identifiant avant blocage progressif. */
    public const SEUIL_IDENTIFIANT = 5;

    /**
     * Échecs tolérés par adresse IP (tous identifiants confondus). Seuil
     * volontairement haut : les membres d'un club partagent souvent la
     * même IP (wifi du club).
     */
    public const SEUIL_IP = 30;

    /**
     * Hash bcrypt d'une valeur aléatoire inconnue : vérifié quand
     * l'identifiant n'existe pas, pour que le temps de réponse ne révèle
     * pas l'existence du compte.
     */
    private const HASH_FACTICE = '$2y$10$4GgDyXpIU4LNIXlR4Yu0deE9whW1fdLD6XDC8xrhDxTftu6HfR9dq';

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

    public function authenticate(string $username, string $plainTextPassword, ?string $ip = null)
    {
        // [SÉCURITÉ — audit 2026-10-19 S2] Clés surveillées : l'identifiant
        // saisi (qu'il existe ou non) et l'adresse IP.
        $cles = ['login-user:' . mb_strtolower(trim($username)) => self::SEUIL_IDENTIFIANT];
        if ($ip !== null && $ip !== '') {
            $cles['login-ip:' . $ip] = self::SEUIL_IP;
        }

        $attente = $this->secondesDeBlocage(array_keys($cles));
        if ($attente > 0) {
            $minutes = (int) ceil($attente / 60);
            $this->error = sprintf(
                'Trop de tentatives de connexion. Réessayez dans %d minute%s.',
                $minutes,
                $minutes > 1 ? 's' : ''
            );
            return false;
        }

        $user = $this->utilisateurManager->findOneByCriteria(['username' => $username]);

        // Toujours un password_verify(), même pour un compte inexistant
        // (temps de réponse identique).
        $hash = is_array($user) && is_string($user['password'] ?? null) ? $user['password'] : self::HASH_FACTICE;
        $motDePasseValide = password_verify($plainTextPassword, $hash);

        if ($user && $motDePasseValide) {
            $this->limiter(fn (LoginThrottleManager $t) => $t->reinitialiser(array_key_first($cles)));

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

            // [SÉCURITÉ — audit 2026-10-19 S5] migrate(true) : l'ancien
            // identifiant de session est DÉTRUIT (anti-fixation), pas
            // seulement remplacé.
            $this->session->migrate(true);

            return true;
        }

        $this->limiter(function (LoginThrottleManager $t) use ($cles) {
            foreach ($cles as $cle => $seuil) {
                $t->enregistrerEchec($cle, $seuil);
            }
        });

        $this->error = "Identifiants incorrects";

        return false;
    }

    /**
     * @param string[] $cles
     */
    private function secondesDeBlocage(array $cles): int
    {
        $attente = 0;
        $this->limiter(function (LoginThrottleManager $t) use ($cles, &$attente) {
            foreach ($cles as $cle) {
                $attente = max($attente, $t->secondesRestantes($cle));
            }
        });
        return $attente;
    }

    /**
     * Exécute une opération du limiteur sans jamais bloquer la connexion
     * si celui-ci est indisponible (ex. migration 010 pas encore
     * appliquée) : l'incident est journalisé, la connexion reste possible.
     */
    private function limiter(callable $operation): void
    {
        try {
            $operation($this->loginThrottle ??= $this->loginThrottle());
        } catch (\Throwable $e) {
            error_log('[AppUserAuthenticator] Limiteur de tentatives indisponible : ' . $e->getMessage());
        }
    }

    public function getError()
    {
        return $this->error;
    }
}
