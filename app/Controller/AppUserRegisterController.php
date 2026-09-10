<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\Exception\MailDeliveryException;
use Epiclub\Engine\MailerService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AppUserRegisterController extends AbstractController
{
    private const RESET_TOKEN_LIFETIME = '+24 hours';
    private const RESET_EMAIL_TIMEOUT_SECONDS = 300; // 5 minutes

    public function account(Request $request): Response
    {
        return $this->render('', []);
    }

    public function edit(Request $request): Response
    {
        return $this->render('', []);
    }

    public function forgotPassword(Request $request): Response
    {
        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            $email = (string) $request->request->get('email', '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_errors['email'] = 'Veuillez entrer une adresse mail valide.';
            }

            if (empty($form_errors)) {
                $utilisateurManager = new UtilisateurManager();
                $user = $utilisateurManager->findOneByCriteria(['email' => $email]);

                // Anti-énumération : on ne révèle jamais si l'email existe.
                if (!$user) {
                    $this->session->getFlashBag()->add('info', 'Un email a été envoyé si le compte existe.');
                    return new RedirectResponse('/mot_de_passe_oublie/confirmation');
                }

                // Anti-spam : on limite la fréquence d'envoi par utilisateur.
                $now = new \DateTime();
                if (!empty($user['reset_email_sent_at'])) {
                    $lastSent = new \DateTime($user['reset_email_sent_at']);
                    $diff = $now->getTimestamp() - $lastSent->getTimestamp();
                    if ($diff < self::RESET_EMAIL_TIMEOUT_SECONDS) {
                        $this->session->getFlashBag()->add('info', 'Un email a déjà été envoyé récemment.');
                        return new RedirectResponse('/mot_de_passe_oublie/confirmation');
                    }
                }

                // Génération du token + persistance
                $token = bin2hex(random_bytes(32));
                $expires = (new \DateTime(self::RESET_TOKEN_LIFETIME))->format('Y-m-d H:i:s');
                $sentAt = $now->format('Y-m-d H:i:s');

                $pdo = $utilisateurManager->getDb();
                $sql = "UPDATE utilisateur
                        SET reset_token = :token,
                            reset_token_expires = :expires,
                            reset_email_sent_at = :sent_at
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'token' => $token,
                    'expires' => $expires,
                    'sent_at' => $sentAt,
                    'id' => $user['id'],
                ]);

                // Envoi de l'email — best effort.
                // Le token est déjà en BDD : un échec SMTP ne doit PAS casser le flux
                // ni révéler au client qu'un problème d'infra existe.
                $clubManager = new ClubManager();
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
                        (new MailerService())->sendEmail($emailContent);
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

                $this->session->getFlashBag()->add('info', 'Un email a été envoyé avec les instructions.');
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

        $utilisateurManager = new UtilisateurManager();
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
                $pdo = $utilisateurManager->getDb();
                $sql = "UPDATE utilisateur
                        SET reset_token = NULL,
                            reset_token_expires = NULL,
                            reset_email_sent_at = NULL
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $utilisateur['id']]);

                $this->session->getFlashBag()->add('error', 'Le lien a expiré.');
                return new RedirectResponse('/');
            }
        }

        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            $password = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            if (empty($password)) {
                $form_errors['password'] = 'Le mot de passe est obligatoire.';
            } elseif (strlen($password) < 6) {
                $form_errors['password'] = 'Le mot de passe doit contenir au moins 6 caractères.';
            } elseif ($password !== $confirmPassword) {
                $form_errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
            }

            if (empty($form_errors)) {
                $utilisateur['password'] = password_hash($password, PASSWORD_DEFAULT);
                $utilisateur['reset_token'] = null;
                $utilisateur['reset_token_expires'] = null;
                $utilisateur['reset_email_sent_at'] = null;
                $utilisateurManager->save($utilisateur);

                $this->session->getFlashBag()->add('success', 'Votre mot de passe a ete reinitialise avec succes.');
                return new RedirectResponse('/se_connecter');
            }
        }

        return $this->render('user_reset_password.twig', [
            'form_errors' => $form_errors,
            'token' => $token,
        ]);
    }

    protected function getBaseUrl(): string
    {
        $configFile = __DIR__ . '/../../.env.local.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            if (isset($config['ROOT_URL']) && !empty($config['ROOT_URL'])) {
                return rtrim($config['ROOT_URL'], '/');
            }
        }

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

        return $protocol . $host . $basePath;
    }
}