<?php

namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Engine\MailerService;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
        // ✅ Vérifier si une demande récente a déjà été faite
        if ($this->session->get('reset_email_sent')) {
            $elapsed = time() - $this->session->get('reset_email_time');
            if ($elapsed < 300) { // 5 minutes
                $this->session->getFlashBag()->add('info', 'Un email de réinitialisation a déjà été envoyé récemment. Vérifiez votre boîte de réception.');
                return new RedirectResponse('/');
            } else {
                $this->session->remove('reset_email_sent');
                $this->session->remove('reset_email_time');
            }
        }

        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            if (false == filter_var($request->request->get('email'), FILTER_VALIDATE_EMAIL)) {
                $form_errors['email'] = 'Veuillez entrer une adresse mail valide.';
            }
            
            if (empty($form_errors)) {
                $utilisateurManager = new UtilisateurManager();
                if ($utilisateur = $utilisateurManager->findOneByCriteria(['email' => $request->request->get('email')])) {
                    $clubManager = new ClubManager();
                    $club = $clubManager->findParameters();
                    
                    $token = bin2hex(random_bytes(32));
                    $utilisateur['reset_token'] = $token;
                    $utilisateur['reset_token_expires'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $utilisateurManager->save($utilisateur);
                    
                    $resetUrl = $this->getBaseUrl() . '/regenerer_mot_de_passe?token=' . $token;
                    
                    if (null !== $club['email']) {
                        $email = $this->createEmail(
                            $club['email'],
                            $utilisateur['email'],
                            'Changement de mot de passe',
                            'email/reset_password.twig',
                            [
                                'club' => $club,
                                'user' => $utilisateur,
                                'reset_url' => $resetUrl,
                                'expiration_date' => new \DateTime('+24 hours')
                            ]
                        );
                        $mailerService = new MailerService();
                        $mailerService->sendEmail($email);
                    }
                }
                
                // ✅ Flag pour éviter les doublons
                $this->session->set('reset_email_sent', true);
                $this->session->set('reset_email_time', time());

                $this->session->getFlashBag()->add('info', 'La procédure de récupération a été envoyée à l\'adresse mail indiquée. Vérifiez votre boîte de réception et vos spams.');
                return new RedirectResponse('/');
            }
        }
        
        return $this->render('user_forgot_password.twig', [
            'form_errors' => $form_errors
        ]);
    }

    public function resetPassword(Request $request)
    {
        $token = $request->query->get('token') ?: $request->request->get('token');
        
        if (!$token) {
            $this->session->getFlashBag()->add('error', 'Token manquant.');
            return new RedirectResponse('/');
        }
        
        $utilisateurManager = new UtilisateurManager();
        $utilisateur = $utilisateurManager->findByResetToken($token);
        
        if (!$utilisateur) {
            $this->session->getFlashBag()->add('error', 'Lien de réinitialisation invalide ou expiré.');
            return new RedirectResponse('/');
        }
        
        // ✅ Vérifier l'expiration avec reset_token_expires
        if (!empty($utilisateur['reset_token_expires'])) {
            $expires = new \DateTime($utilisateur['reset_token_expires']);
            $now = new \DateTime();
            if ($now > $expires) {
                // Token expiré
                $utilisateur['reset_token'] = null;
                $utilisateur['reset_token_expires'] = null;
                $utilisateurManager->save($utilisateur);
                $this->session->getFlashBag()->add('error', 'Le lien de réinitialisation a expiré. Veuillez faire une nouvelle demande.');
                return new RedirectResponse('/');
            }
        } else {
            // Fallback : si pas de date d'expiration, on vérifie avec date_creation (24h)
            $tokenDate = new \DateTime($utilisateur['date_creation']);
            $now = new \DateTime();
            $interval = $tokenDate->diff($now);
            if ($interval->h >= 24 || $interval->days > 0) {
                $utilisateur['reset_token'] = null;
                $utilisateur['reset_token_expires'] = null;
                $utilisateurManager->save($utilisateur);
                $this->session->getFlashBag()->add('error', 'Le lien de réinitialisation a expiré. Veuillez faire une nouvelle demande.');
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
                
                $this->session->getFlashBag()->add('success', 'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.');
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