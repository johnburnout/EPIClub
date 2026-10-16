<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\EnvironmentFileParser;
use Epiclub\Engine\MailerService;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\RootUrlValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class IndexController extends AbstractController
{
    // ==================================================================
    // [REFACTOR #74] Factories Vague 11 — mockables en test Unit.
    // ==================================================================

    protected function environmentFileParser(string $path): EnvironmentFileParser
    {
        return new EnvironmentFileParser($path);
    }

    protected function mailerService(ConfigProvider $config): MailerServiceInterface
    {
        return new MailerService($config);
    }

    public function index(Request $request): Response
    {
        if (!$this->isGranted('ROLE_USER')) {
            return $this->redirectTo('/se_connecter');
        }
        return $this->redirectTo('/tableau_de_bord');
    }

    public function dashboard(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        return $this->render('tableau_de_bord.twig', []);
    }

    public function systemSettings(Request $request): Response
    {
        // ⚠️ Configuration système (SMTP) : réservée au SUPER_ADMIN
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        
        // Lecture des paramètres actuels depuis .env.local.php
        $mailerDsn = getenv('MAILER_DSN') ?: $_ENV['MAILER_DSN'] ?? '';
        $mailerFrom = getenv('MAILER_FROM') ?: $_ENV['MAILER_FROM'] ?? '';
        $mailerName = getenv('MAILER_NAME') ?: $_ENV['MAILER_NAME'] ?? '';
        
        $dsnParts = parse_url($mailerDsn);
        $smtpHost = $dsnParts['host'] ?? '';
        $smtpPort = $dsnParts['port'] ?? 25;
        $smtpUser = $dsnParts['user'] ?? '';
        // [SÉCURITÉ] Ne JAMAIS renvoyer le mot de passe au client.
        parse_str($dsnParts['query'] ?? '', $query);
        $smtpEncryption = $query['encryption'] ?? '';
        
        // [VAGUE 13 - #59] Lecture de ROOT_URL via ConfigProvider
        // (source unique de vérité, cf. Vague 12). Si la clé est absente,
        // on expose une chaîne vide — le champ HTML sera vide, l'utilisateur
        // pourra la saisir.
        $rootUrl = '';
        $envFile = __DIR__ . '/../../.env.local.php';
        if (is_file($envFile)) {
            try {
                $configProvider = new ConfigProvider($envFile);
                $rootUrl = (string) $configProvider->get('ROOT_URL', '');
            } catch (\Throwable $e) {
                // Config illisible : on n'empêche pas l'affichage de la page SMTP.
                error_log('[IndexController] systemSettings: ' . $e->getMessage());
            }
        }
        
        return $this->render('system_settings.twig', [
            'settings' => [
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_username' => $smtpUser,
                'smtp_encryption' => $smtpEncryption,
                'mail_from' => $mailerFrom,
                'mail_from_name' => $mailerName,
                'root_url' => $rootUrl,
            ]
        ]);
    }

    /**
     * [REFACTOR #97 - Dette 1 soldée] Renommée updateSystemSettings() :
     * l'action gère SMTP **et** ROOT_URL depuis #59. L'ancienne route
     * /update-smtp est conservée en alias (double mapping POST, cf.
     * ressources/routes.php) pour ne pas casser les bookmarks.
     */
    public function updateSystemSettings(Request $request): Response
    {
        // ⚠️ Configuration système (SMTP + ROOT_URL) : réservée au SUPER_ADMIN
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        
        // [SÉCURITÉ] Vérification CSRF avant toute modification de .env.local.php
        $this->validateCsrf($request);
        
        // Récupération des données du formulaire
        $host = (string) $request->request->get('smtp_host', '');
        $port = (string) $request->request->get('smtp_port', '');
        $user = (string) $request->request->get('smtp_username', '');
        $pass = (string) $request->request->get('smtp_password', '');
        $encryption = (string) $request->request->get('smtp_encryption', '');
        $from = (string) $request->request->get('mail_from', '');
        $fromName = (string) $request->request->get('mail_from_name', '');
        $rawRootUrl = trim((string) $request->request->get('root_url', ''));
        
        // Validation basique SMTP (iso-comportement)
        if (empty($host) || empty($port) || empty($user) || empty($from)) {
            $this->session->getFlashBag()->add('error', 'Tous les champs obligatoires doivent être remplis.');
            return $this->redirectTo('/admin/system-settings');
        }
        
        // [VAGUE 13 - #59] Validation ROOT_URL si non vide.
        // Si vide → no-op (on ne touche pas à la clé existante).
        // On valide AVANT toute écriture : si ROOT_URL est invalide, on ne
        // persiste rien (ni SMTP ni ROOT_URL) — comportement « tout ou rien ».
        $normalizedRootUrl = null;
        if ($rawRootUrl !== '') {
            try {
                $normalizedRootUrl = RootUrlValidator::validate($rawRootUrl);
            } catch (\InvalidArgumentException $e) {
                $this->session->getFlashBag()->add('error', $e->getMessage());
                return $this->redirectTo('/admin/system-settings');
            }
        }
        
        // Lecture du fichier .env.local.php actuel
        $envFile = __DIR__ . '/../../.env.local.php';
        if (!file_exists($envFile)) {
            $this->session->getFlashBag()->add('error', 'Fichier de configuration introuvable.');
            return $this->redirectTo('/admin/system-settings');
        }
        
        $current = require $envFile;
        if (!is_array($current)) {
            $this->session->getFlashBag()->add('error', 'Fichier de configuration invalide.');
            return $this->redirectTo('/admin/system-settings');
        }
        
        // [SÉCURITÉ] Si le champ mot de passe est vide, on conserve l'ancien.
        if ($pass === '') {
            $currentDsn = $current['MAILER_DSN'] ?? '';
            $currentParts = parse_url($currentDsn);
            $pass = $currentParts['pass'] ?? '';
        }
        
        // Encoder le mot de passe (caractères spéciaux comme @, :, /)
        $passEncoded = urlencode($pass);
        
        // Construire le nouveau DSN
        $dsn = sprintf('smtp://%s:%s@%s:%s', $user, $passEncoded, $host, $port);
        if ($encryption !== '' && $encryption !== 'none') {
            $dsn .= '?encryption=' . $encryption;
        }
        
        // [VAGUE 13 - #59] Migration vers EnvironmentFileParser
        // (bénéficie de l'échappement var_export() de l'issue #49).
        // [REFACTOR #74] Factory protected — mockable en test Unit.
        // [REFACTOR #98 - Dette 2] setAll() : UNE seule réécriture du
        // fichier au lieu de 3-4 (une par set()).
        $parser = $this->environmentFileParser($envFile);

        $values = [
            'MAILER_DSN'  => $dsn,
            'MAILER_FROM' => $from,
            'MAILER_NAME' => $fromName,
        ];

        // [VAGUE 13 - #59] Persister ROOT_URL uniquement si fournie et valide
        if ($normalizedRootUrl !== null) {
            $values['ROOT_URL'] = $normalizedRootUrl;
        }

        $parser->setAll($values);
        
        $this->session->getFlashBag()->add('success', 'Configuration mise à jour avec succès.');
        return $this->redirectTo('/admin/system-settings');
    }

    public function testMail(Request $request): Response
    {
        // ⚠️ Test de la configuration système : réservé au SUPER_ADMIN
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        // [SÉCURITÉ] Vérification CSRF avant envoi d'email
        $this->validateCsrf($request);

        try {
            // Charger .env.local.php
            $envFile = __DIR__ . '/../../.env.local.php';
            if (!file_exists($envFile)) {
                return new JsonResponse(['success' => false, 'error' => 'Fichier .env.local.php introuvable']);
            }
            $env = require $envFile;
            $from = $env['MAILER_FROM'] ?? '';
            $fromName = $env['MAILER_NAME'] ?? 'EPIClub';

            if (empty($from)) {
                return new JsonResponse(['success' => false, 'error' => 'Adresse expéditeur manquante']);
            }

            // [VAGUE 13 - #50c] Instancier le service mailer via ConfigProvider.
            // Le DSN est lu depuis .env.local.php (plus de $_ENV).
            // [REFACTOR #74] Factory protected — mockable en test Unit.
            $configProvider = new ConfigProvider($envFile);
            $mailer = $this->mailerService($configProvider);

            // Créer l'email de test
            $email = new \Symfony\Component\Mime\Email();
            $email->from($fromName . ' <' . $from . '>')
                ->to($from)
                ->subject('[TEST] Configuration SMTP EPIClub')
                ->text("Ceci est un email de test.\n\nSi vous recevez ce message, votre configuration SMTP est correcte.\n\nDate : " . date('d/m/Y H:i:s'));

            $mailer->sendEmail($email);

            return new JsonResponse([
                'success' => true,
                'message' => '✅ Email de test envoyé avec succès à ' . $from
            ]);

        } catch (\Throwable $e) {
            // [SÉCURITÉ] Log serveur, message générique au client
            error_log(sprintf(
                '[IndexController] testMail failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            return new JsonResponse([
                'success' => false,
                'error' => 'Erreur lors de l\'envoi de l\'email de test. Consultez les logs serveur pour plus de détails.'
            ], 500);
        }
    }
}