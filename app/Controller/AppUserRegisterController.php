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
        $timeout = 60;
        
        if ($this->session->get('reset_email_sent')) {
            $elapsed = time() - $this->session->get('reset_email_time');
            if ($elapsed < $timeout) {
                $this->session->getFlashBag()->add('info', 'Un email a déjà été envoyé récemment.');
                return new RedirectResponse('/');
            } else {
                $this->session->remove('reset_email_sent');
                $this->session->remove('reset_email_time');
            }
        }
        
        $form_errors = [];
        $submitToken = bin2hex(random_bytes(16));
        
        if ($request->getMethod() === 'POST') {
            $submittedToken = $request->request->get('submit_token');
            $sessionToken = $this->session->get('last_submit_token');
            
            if (!$submittedToken || !$sessionToken || $submittedToken !== $sessionToken) {
                $this->session->getFlashBag()->add('error', 'Formulaire invalide ou déjà soumis.');
                return new RedirectResponse('/mot_de_passe_oublie');
            }
            
            $this->session->remove('last_submit_token');
            
            $email = $request->request->get('email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_errors['email'] = 'Veuillez entrer une adresse mail valide.';
            }
            
            if (empty($form_errors)) {
                $utilisateurManager = new UtilisateurManager();
                $user = $utilisateurManager->findOneByCriteria(['email' => $email]);
                
                if (!$user) {
                    $this->session->set('reset_email_sent', true);
                    $this->session->set('reset_email_time', time());
                    $this->session->getFlashBag()->add('info', 'La procédure de récupération a été envoyée à l\'adresse mail indiquée.');
                    return new RedirectResponse('/mot_de_passe_oublie/confirmation');
                }
                
                if (!empty($user['reset_email_sent']) && $user['reset_email_sent'] == 1) {
                    if (!empty($user['reset_email_sent_at'])) {
                        $lastSent = new \DateTime($user['reset_email_sent_at']);
                        $now = new \DateTime();
                        if ($now->getTimestamp() - $lastSent->getTimestamp() < $timeout) {
                            $this->session->getFlashBag()->add('info', 'Un email a déjà été envoyé récemment.');
                            return new RedirectResponse('/');
                        }
                    }
                }
                
                $clubManager = new ClubManager();
                $club = $clubManager->findParameters();
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                $sentAt = date('Y-m-d H:i:s');
                
                $pdo = $utilisateurManager->getDb();
                $sql = "UPDATE utilisateur 
                SET reset_token = :token,
                reset_token_expires = :expires,
                reset_email_sent_at = :sent_at,
                reset_email_sent = 1
                WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'token' => $token,
                    'expires' => $expires,
                    'sent_at' => $sentAt,
                    'id' => $user['id']
                ]);
                
                $this->session->set('reset_email_sent', true);
                $this->session->set('reset_email_time', time());
                
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
                
                $this->session->getFlashBag()->add('info', 'La procédure de récupération a été envoyée à l\'adresse mail indiquée.');
                return new RedirectResponse('/mot_de_passe_oublie/confirmation');
            }
            
            return $this->render('user_forgot_password.twig', [
                'form_errors' => $form_errors,
                'submit_token' => $submitToken,
                'email' => $email
            ]);
        }
        
        $this->session->set('last_submit_token', $submitToken);
        return $this->render('user_forgot_password.twig', [
            'form_errors' => $form_errors,
            'submit_token' => $submitToken
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
        
        // ✅ Vérification d'expiration (optionnelle)
        if (isset($utilisateur['reset_token_expires']) && $utilisateur['reset_token_expires'] !== null) {
            $now = new \DateTime();
            $expires = new \DateTime($utilisateur['reset_token_expires']);
            if ($now > $expires) {
                $utilisateur['reset_token'] = null;
                $utilisateur['reset_token_expires'] = null;
                $utilisateurManager->save($utilisateur);
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
                $utilisateurManager->save($utilisateur);
                
                $this->session->getFlashBag()->add('success', 'Votre mot de passe a été réinitialisé avec succès.');
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