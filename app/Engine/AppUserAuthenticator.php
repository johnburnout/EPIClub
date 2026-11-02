<?php

namespace Epiclub\Engine;

use Epiclub\Engine\Session;
use Epiclub\Domain\DoubleAuthentificationManager;
use Epiclub\Domain\LoginThrottleManager;
use Epiclub\Domain\UtilisateurManager;

class AppUserAuthenticator
{
    protected UtilisateurManager $utilisateurManager;
    private string $error = '';
    private ?LoginThrottleManager $loginThrottle = null;
    private bool $deuxiemeFacteurEnAttente = false;

    /**
     * [migration 020] Clé de session de la connexion en attente du second
     * facteur : ['id' => int, 'expire' => int]. La session « user »
     * n'est PAS ouverte tant que le second facteur n'est pas vérifié.
     */
    public const SESSION_EN_ATTENTE = 'auth_second_facteur';

    /** Délai laissé pour saisir le second facteur, en secondes. */
    public const DELAI_SECOND_FACTEUR = 300;

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
        // [migration 020] Double authentification
        'totp_secret',
        'totp_active_le',
        'totp_dernier_pas',
        'codes_secours',
    ];

    /**
     * [migration 020] Clé, dans la session « user », de l'horodatage de
     * la dernière preuve d'identité (connexion ou mot de passe confirmé)
     * : les réglages de double authentification l'exigent récente.
     */
    public const CLE_AUTHENTIFIE_LE = 'authentifie_le';

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

        // [2026-10-25] Connexion par identifiant ou par email : l'identifiant
        // d'abord (un identifiant peut contenir « @ »), puis l'email.
        $user = $this->utilisateurManager->findOneByCriteria(['username' => $username]);
        if (!$user && str_contains($username, '@')) {
            $user = $this->utilisateurManager->findOneByCriteria(['email' => trim($username)]);
        }

        // Toujours un password_verify(), même pour un compte inexistant
        // (temps de réponse identique).
        $hash = is_array($user) && is_string($user['password'] ?? null) ? $user['password'] : self::HASH_FACTICE;
        $motDePasseValide = password_verify($plainTextPassword, $hash);

        if ($user && $motDePasseValide) {
            $this->limiter(fn (LoginThrottleManager $t) => $t->reinitialiser(array_key_first($cles)));

            // [migration 020] Second facteur configuré : la session
            // utilisateur n'est pas encore ouverte, seule la connexion en
            // attente est mémorisée (5 minutes).
            if ($this->deuxiemeFacteurRequis($user)) {
                $this->session->remove('user');
                $this->session->set(self::SESSION_EN_ATTENTE, [
                    'id'     => (int) $user['id'],
                    'expire' => time() + self::DELAI_SECOND_FACTEUR,
                ]);
                $this->session->migrate(true);
                $this->deuxiemeFacteurEnAttente = true;
                return true;
            }

            $this->ouvrirSession($user);

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
     * Ouvre la session de l'utilisateur (identité prouvée : mot de passe,
     * puis second facteur s'il est configuré, ou passkey).
     *
     * @param array<string, mixed> $user ligne complète de la table utilisateur
     */
    public function ouvrirSession(array $user): void
    {
        $this->session->remove(self::SESSION_EN_ATTENTE);

        // [SÉCURITÉ — audit P34] La session ne reçoit que la
        // projection non sensible de l'utilisateur.
        $sessionUser = array_diff_key(
            $user,
            array_flip(self::SESSION_EXCLUDED_FIELDS)
        );

        // [SÉCURITÉ — audit C3/P22] save() ne peut plus réécrire
        // password/role (ALLOWED_FIELDS restreint) ; on ne transmet
        // de toute façon que la projection non sensible.
        $sessionUser['derniere_connexion'] = date('Y-m-d');
        $this->utilisateurManager->save($sessionUser);

        $sessionUser[self::CLE_AUTHENTIFIE_LE] = time();
        $this->session->set('user', $sessionUser);

        // [SÉCURITÉ — audit 2026-10-19 S5] migrate(true) : l'ancien
        // identifiant de session est DÉTRUIT (anti-fixation), pas
        // seulement remplacé.
        $this->session->migrate(true);
    }

    /**
     * [migration 020] La connexion attend-elle le second facteur (dernier
     * authenticate() réussi) ?
     */
    public function deuxiemeFacteurEnAttente(): bool
    {
        return $this->deuxiemeFacteurEnAttente;
    }

    /**
     * [migration 020] Un second facteur (application ou passkey) est-il
     * configuré pour ce compte ? Il est alors exigé quel que soit le rôle
     * actuel (un administrateur rétrogradé le garde).
     *
     * @param array<string, mixed> $user
     */
    protected function deuxiemeFacteurRequis(array $user): bool
    {
        if (!empty($user['totp_active_le'])) {
            return true;
        }
        // Une base en panne fait échouer la connexion (exception) plutôt
        // que d'ignorer un second facteur peut-être configuré.
        return $this->doubleAuthentification()->compterPasskeys((int) $user['id']) > 0;
    }

    /** Factory — surchargée en test Unit. */
    protected function doubleAuthentification(): DoubleAuthentificationManager
    {
        return new DoubleAuthentificationManager($this->utilisateurManager->getDb());
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
