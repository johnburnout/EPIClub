<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\LoginThrottleManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Exception\MailDeliveryException;
use Epiclub\Engine\MailerService;
use Epiclub\Engine\MailerServiceInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AppUserRegisterController extends AbstractController
{
    private const RESET_TOKEN_LIFETIME = '+24 hours';
    private const RESET_EMAIL_TIMEOUT_SECONDS = 300; // 5 minutes
    
    // ==================================================================
    // [REFACTOR VAGUE 11] Factories pour la testabilité Unit.
    //
    // Chaque méthode retourne une nouvelle instance concrète.
    // Les sous-classes de test (TestableAppUserRegisterController)
    // surchargent ces factories pour injecter des mocks et éviter
    // l'ouverture PDO.
    // ==================================================================
    
    protected function utilisateurManager(): UtilisateurManager
    {
        return new UtilisateurManager();
    }
    
    protected function clubManager(): ClubManager
    {
        return new ClubManager();
    }
    
    /**
     * [SÉCURITÉ — audit 2026-10-19 S2] Limiteur des demandes de
     * réinitialisation — surchargé en test Unit.
     */
    protected function loginThrottle(): LoginThrottleManager
    {
        return new LoginThrottleManager();
    }

    /** Demandes de réinitialisation tolérées par IP (fenêtre de 24 h). */
    public const SEUIL_RESET_IP = 5;

    /**
     * [SÉCURITÉ — audit 2026-10-19 S7] Message UNIQUE, que le compte
     * existe, n'existe pas, ou qu'un mail vienne d'être envoyé : la page
     * ne doit pas permettre de savoir si une adresse est inscrite.
     */
    public const MESSAGE_RESET = "Si un compte correspond à cette adresse, un email contenant un lien de réinitialisation vient d'être envoyé.";

    /**
     * true si l'IP a dépassé son quota de demandes. Ne bloque jamais si
     * le limiteur est indisponible (journalisé).
     */
    private function demandeResetLimitee(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }
        try {
            $limiteur = $this->loginThrottle();
            $cle = 'reset-ip:' . $ip;
            if ($limiteur->secondesRestantes($cle) > 0) {
                return true;
            }
            $limiteur->enregistrerEchec($cle, self::SEUIL_RESET_IP);
        } catch (\Throwable $e) {
            error_log('[forgotPassword] Limiteur indisponible : ' . $e->getMessage());
        }
        return false;
    }

    protected function mailerService(): MailerServiceInterface
    {
        return new MailerService($this->configProvider());
    }

    public function forgotPassword(Request $request): Response
    {
        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement
            $this->validateCsrf($request);

            $email = (string) $request->request->get('email', '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_errors['email'] = 'Veuillez entrer une adresse mail valide.';
            }

            if (empty($form_errors) && $this->demandeResetLimitee($request->getClientIp())) {
                // Quota dépassé : même réponse que d'habitude, aucun mail.
                error_log('[forgotPassword] Demandes trop nombreuses depuis cette IP — ignorée.');
                $this->session->getFlashBag()->add('info', self::MESSAGE_RESET);
                return new RedirectResponse('/mot_de_passe_oublie/confirmation');
            }

            if (empty($form_errors)) {
                $utilisateurManager = $this->utilisateurManager();
                $user = $utilisateurManager->findOneByCriteria(['email' => $email]);

                // Anti-énumération : on ne révèle jamais si l'email existe.
                if (!$user) {
                    $this->session->getFlashBag()->add('info', self::MESSAGE_RESET);
                    return new RedirectResponse('/mot_de_passe_oublie/confirmation');
                }

                // Anti-spam : on limite la fréquence d'envoi par utilisateur.
                $now = new \DateTime();
                if (!empty($user['reset_email_sent_at'])) {
                    $lastSent = new \DateTime($user['reset_email_sent_at']);
                    $diff = $now->getTimestamp() - $lastSent->getTimestamp();
                    if ($diff < self::RESET_EMAIL_TIMEOUT_SECONDS) {
                        $this->session->getFlashBag()->add('info', self::MESSAGE_RESET);
                        return new RedirectResponse('/mot_de_passe_oublie/confirmation');
                    }
                }

                // Génération du token + persistance
                $token = bin2hex(random_bytes(32));
                $expires = (new \DateTime(self::RESET_TOKEN_LIFETIME))->format('Y-m-d H:i:s');
                $sentAt = $now->format('Y-m-d H:i:s');
                
                $utilisateurManager->setResetToken(
                    (int) $user['id'],
                    $token,
                    $expires,
                    $sentAt
                );

                // Envoi de l'email — best effort.
                // Le token est déjà en BDD : un échec SMTP ne doit PAS casser le flux
                // ni révéler au client qu'un problème d'infra existe.
                $clubManager = $this->clubManager();
                $club = $clubManager->findParameters();
                $resetUrl = $this->getBaseUrl() . '/regenerer_mot_de_passe?token=' . $token;

                if (!empty($club['email'])) {
                    $emailContent = $this->createEmail(
                        $club['email'],
                        $user['email'],
                        'Changement de mot de passe',
                        'email/reset_password.twig',
                        [
                            'club' => $club,
                            'user' => $user,
                            'reset_url' => $resetUrl,
                            'expiration_date' => new \DateTime(self::RESET_TOKEN_LIFETIME),
                        ]
                    );

                    try {
                        $this->mailerService()->sendEmail($emailContent);
                    } catch (MailDeliveryException $e) {
                        // On loggue côté serveur pour pouvoir alerter / monitorer.
                        // On NE remonte PAS au client : même réponse qu'en cas de succès.
                        error_log(sprintf(
                            '[forgotPassword] Mail delivery failed for user id=%s: %s',
                            $user['id'],
                            $e->getMessage()
                        ));
                    }
                } else {
                    error_log('[forgotPassword] Club email is not configured — no reset email sent.');
                }

                $this->session->getFlashBag()->add('info', self::MESSAGE_RESET);
                return new RedirectResponse('/mot_de_passe_oublie/confirmation');
            }
        }

        return $this->render('user_forgot_password.twig', [
            'form_errors' => $form_errors,
        ]);
    }

    public function forgotPasswordConfirm(Request $request): Response
    {
        return $this->render('user_forgot_password_confirm.twig');
    }

    public function resetPassword(Request $request): Response
    {
        $token = $request->query->get('token') ?: $request->request->get('token');

        if (!$token) {
            $this->session->getFlashBag()->add('error', 'Token manquant.');
            return new RedirectResponse('/');
        }

        $utilisateurManager = $this->utilisateurManager();
        $utilisateur = $utilisateurManager->findOneByCriteria(['reset_token' => $token]);

        if (!$utilisateur) {
            $this->session->getFlashBag()->add('error', 'Lien de réinitialisation invalide ou expiré.');
            return new RedirectResponse('/');
        }

        // Vérification d'expiration
        if (isset($utilisateur['reset_token_expires']) && $utilisateur['reset_token_expires'] !== null) {
            $now = new \DateTime();
            $expires = new \DateTime($utilisateur['reset_token_expires']);
            if ($now > $expires) {
                $utilisateurManager->clearResetToken((int) $utilisateur['id']);
                
                $this->session->getFlashBag()->add('error', 'Le lien a expiré.');
                return new RedirectResponse('/');
            }
        }

        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement.
            // Nota : le token de reset (query/body `token`) a déjà été validé
            // ci-dessus — ce sont deux choses différentes. Le token métier
            // prouve l'identité, le CSRF prouve l'origine de la requête.
            $this->validateCsrf($request);

            $password = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            if (empty($password)) {
                $form_errors['password'] = 'Le mot de passe est obligatoire.';
            } elseif ($error = UtilisateurManager::validatePassword($password)) {
                $form_errors['password'] = $error;
            } elseif ($password !== $confirmPassword) {
                $form_errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
            }

            if (empty($form_errors)) {
                // [SÉCURITÉ — audit C3] Le reset ne touche QUE le mot de
                // passe : jamais de save() complet (qui réécrirait le
                // rôle et les autres colonnes avec des données périmétrées).
                $utilisateurManager->changePassword(
                    (int) $utilisateur['id'],
                    password_hash($password, PASSWORD_DEFAULT)
                );
                $utilisateurManager->clearResetToken((int) $utilisateur['id']);

                $this->session->getFlashBag()->add('success', 'Votre mot de passe a ete reinitialise avec succes.');
                return new RedirectResponse('/se_connecter');
            }
        }

        return $this->render('user_reset_password.twig', [
            'form_errors' => $form_errors,
            'token' => $token,
        ]);
    }
}