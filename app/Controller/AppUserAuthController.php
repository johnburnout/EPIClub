<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Epiclub\Domain\JournalActiviteManager;
use Epiclub\Engine\AppUserAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AppUserAuthController extends AbstractController
{
    /**
     * [#102 - Dette 21] Factory Authenticator — mockable en test Unit
     * (pattern Vague 11). Remplace le `new AppUserAuthenticator()` en
     * dur dans login().
     */
    protected function authenticator(): AppUserAuthenticator
    {
        return new AppUserAuthenticator($this->session);
    }

    /**
     * [SÉCURITÉ — audit 2026-10-19 S4] Une cible de redirection n'est
     * acceptée que si c'est un chemin LOCAL sans ambiguïté.
     *
     * L'ancienne garde (« commence par / mais pas // ») laissait passer
     * « /\\evil.com » : les navigateurs lisent « /\\ » comme « // »,
     * donc une redirection vers https://evil.com (hameçonnage). Désormais :
     * liste blanche de caractères (aucun « \\ », espace ou caractère de
     * contrôle), et ni « // » ni « /\\ » en tête.
     */
    public static function estRedirectionLocale(string $cible): bool
    {
        return strlen($cible) <= 2048
            && preg_match('#^/(?![/\\\\])[A-Za-z0-9/_.~%?=&+\-]*$#', $cible) === 1;
    }

    public function login(Request $request): Response
    {
        // Le token est généré par TwigRenderer lors du rendu du template.
        // On le récupère pour la vérification.
        if (!$this->session->has('csrf_token')) {
            $this->session->set('csrf_token', bin2hex(random_bytes(32)));
        }
        
        $error_autentification = null;
        $form_errors = [];
        $csrf_error = false;
        
        if ($request->getMethod() === 'POST') {
            $submittedToken = $request->request->get('csrf_token');
            $sessionToken = $this->session->get('csrf_token');
            
            if (!$submittedToken || !hash_equals((string)$sessionToken, (string)$submittedToken)) {
                // Token invalide : régénérer pour éviter les rejeux
                $this->session->set('csrf_token', bin2hex(random_bytes(32)));
                $csrf_error = true;
                $error_autentification = 'Erreur de sécurité : Token CSRF invalide. Veuillez réessayer.';
            }
            
            if (!$csrf_error) {
                if (!$request->request->has('username')) {
                    $form_errors['username'] = "L'identifiant ou l'email est requis.";
                }
                if (!$request->request->has('password')) {
                    $form_errors['password'] = "Le mot de passe est requis.";
                }
                
                if (empty($form_errors)) {
                    $appUserAuthenticator = $this->authenticator();

                    if ($appUserAuthenticator->authenticate(
                        (string) $request->request->get('username'),
                        (string) $request->request->get('password'),
                        $request->getClientIp()
                    )) {
                        // [SÉCURITÉ] Régénérer le token après login (anti-fixation de session)
                        $this->session->set('csrf_token', bin2hex(random_bytes(32)));

                        // [migration 020] Second facteur configuré : la
                        // session n'est ouverte qu'après sa vérification.
                        // La cible ?redirect= est conservée pour la suite.
                        if ($appUserAuthenticator->deuxiemeFacteurEnAttente()) {
                            $redirect = $request->query->get('redirect');
                            $enAttente = $this->session->get(AppUserAuthenticator::SESSION_EN_ATTENTE);
                            if (is_array($enAttente) && is_string($redirect) && self::estRedirectionLocale($redirect)) {
                                $enAttente['redirect'] = $redirect;
                                $this->session->set(AppUserAuthenticator::SESSION_EN_ATTENTE, $enAttente);
                            }
                            return new RedirectResponse('/se_connecter/verification');
                        }

                        $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, 'Connexion', $request);

                        // Honorer ?redirect= (ex. scan QR non connecté :
                        // /se_connecter?redirect=/qr/{id} — le formulaire
                        // sans attribut action poste sur l'URL courante,
                        // le paramètre survit donc au POST).
                        // [SÉCURITÉ] Chemin local uniquement : ^/(?!/)
                        // rejette les URL absolues (https://…) et
                        // scheme-relative (//hôte) — anti open redirect.
                        $redirect = $request->query->get('redirect');
                        if (is_string($redirect) && self::estRedirectionLocale($redirect)) {
                            return new RedirectResponse($redirect);
                        }

                        // [#102 - Dette 21] Fix de bug documenté : la
                        // réponse est RETOURNÉE (le dispatcher l'envoie).
                        // Avant, send() était appelé ici et le code
                        // continuait : le formulaire de login était
                        // rendu ET envoyé en plus de la redirection
                        // (double sortie, « headers already sent »).
                        return new RedirectResponse('.');
                    }

                    $error_autentification = $appUserAuthenticator->getError();

                    // [migration 019] Échec (identifiants incorrects ou
                    // compte temporairement bloqué).
                    // [SÉCURITÉ — audit 2026-11-07] Jamais le texte saisi
                    // (un mot de passe tapé dans le champ identifiant
                    // resterait lisible par les administrateurs) : le
                    // compte visé s'il existe, sinon « identifiant inconnu ».
                    $compte = $appUserAuthenticator->compteVise();
                    $this->journaliser(
                        JournalActiviteManager::TYPE_CONNEXION,
                        ($compte !== null ? 'Échec de connexion : ' : 'Échec de connexion (identifiant inconnu) : ')
                            . $error_autentification,
                        $request,
                        $compte,
                    );
                }
            }
        }
        
        return $this->render('user_login.twig', [
            'error_autentification' => $error_autentification,
            'form_errors' => $form_errors,
            // [migration 020] Bouton « Se connecter avec une passkey »
            'redirect' => is_string($request->query->get('redirect')) && self::estRedirectionLocale($request->query->get('redirect'))
                ? $request->query->get('redirect') : null,
        ]);
    }

    public function logout(Request $request): Response
    {
        if ($request->getMethod() === 'POST') {
            $token = $request->request->get('csrf_token');
            if ($token && !hash_equals((string) $this->session->get('csrf_token'), (string) $token)) {
                $ip = $request->server->get('REMOTE_ADDR', 'inconnue');
                error_log("⚠️ Déconnexion avec token CSRF invalide - IP: $ip");
            }
        }

        if (is_array($this->session->get('user'))) {
            $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, 'Déconnexion', $request);
        }

        $this->session->clear();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // [#102 - Dette 21] Fix de bug documenté : la réponse est
        // RETOURNÉE (le dispatcher l'envoie). Avant, send() était appelé
        // ici puis la méthode retournait null → send() sur null dans le
        // dispatcher (Error fatale après envoi du header Location).
        return new RedirectResponse('/');
    }
}