<?php

namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Engine\MailerService;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

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
            $email = $request->request->get('email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_errors['email'] = 'Veuillez entrer une adresse mail valide.';
            }
            
            if (empty($form_errors)) {
                $utilisateurManager = new UtilisateurManager();
                $user = $utilisateurManager->findOneByCriteria(['email' => $email]);
                
                // Ne pas révéler si l'email existe ou non
                if (!$user) {
                    $this->session->getFlashBag()->add('info', 'Un email a été envoyé si le compte existe.');
                    return new RedirectResponse('/mot_de_passe_oublie/confirmation');
                }
                
                // Vérifier le délai depuis le dernier envoi
                $timeout = 60; // secondes
                $now = new \DateTime();
                if (!empty($user['reset_email_sent_at'])) {
                    $lastSent = new \DateTime($user['reset_email_sent_at']);
                    $diff = $now->getTimestamp() - $lastSent->getTimestamp();
                    if ($diff < $timeout) {
                        $this->session->getFlashBag()->add('info', 'Un email a déjà été envoyé récemment.');
                        return new RedirectResponse('/');
                    }
                }
                
                // Génération du token
                $token = bin2hex(random_bytes(32));
                $expires = (new \DateTime('+24 hours'))->format('Y-m-d H:i:s');
                $sentAt = $now->format('Y-m-d H:i:s');
                
                // Mise à jour de l'utilisateur
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
                    'id' => $user['id']
                ]);
                
                // Envoi de l'email
                $clubManager = new ClubManager();
                $club = $clubManager->findParameters();
                $resetUrl = $this->getBaseUrl() . '/regenerer_mot_de_passe?token=' . $token;
                
                if (null !== $club['email']) {
                    $emailContent = $this->createEmail(
                        $club['email'],
                        $user['email'],
                        'Changement de mot de passe',
                        'email/reset_password.twig',
                        [
                            'club' => $club,
                            'user' => $user,
                            'reset_url' => $resetUrl,
                            'expiration_date' => new \DateTime('+24 hours')
                        ]
                    );
                    $mailerService = new MailerService();
                    $mailerService->sendEmail($emailContent);
                }
                
                $this->session->getFlashBag()->add('info', 'Un email a été envoyé avec les instructions.');
                return new RedirectResponse('/mot_de_passe_oublie/confirmation');
            }
        }
        
        return $this->render('user_forgot_password.twig', [
            'form_errors' => $form_errors
        ]);
    }
    
    public function forgotPasswordConfirm(Request $request): Response
    {
        return $this->render('user_forgot_password_confirm.twig');
    }

    public function resetPassword(Request $request)
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
                // Nettoyer le token
                $pdo = $utilisateurManager->getDb();
                $sql = "UPDATE utilisateur SET reset_token = NULL, reset_token_expires = NULL, reset_email_sent_at = NULL WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $utilisateur['id']]);
                $this->session->getFlashBag()->add('error', 'Le lien a expiré.');
                return new RedirectResponse('/');
            }
        }
        
        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirm_password');
            
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
            'token' => $token
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