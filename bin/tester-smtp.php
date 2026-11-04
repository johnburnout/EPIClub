<?php

declare(strict_types=1);

/**
 * Teste la configuration SMTP de l'instance (.env.local.php), depuis le
 * serveur qui l'héberge : pour la console d'hébergement, ou en SSH.
 *
 * Usage (sous l'utilisateur du domaine, jamais en root) :
 *
 *   php bin/tester-smtp.php [--destinataire=<email>] [--connexion] [--json]
 *
 *   --connexion       connexion et authentification seulement, aucun email
 *                     envoyé
 *   --destinataire=…  adresse de l'email de test (par défaut : l'expéditeur
 *                     MAILER_FROM, comme le bouton de test de la page
 *                     Configuration système)
 *
 * La réponse reprend la configuration lue (serveur, port, identifiant,
 * expéditeur), jamais le mot de passe. En échec, le message du serveur SMTP
 * est rendu tel quel : c'est lui qui permet de corriger la configuration.
 *
 * Codes de sortie : 0 succès, 1 erreur inattendue, 2 usage, 3 instance non
 * installée ou SMTP non configuré, 6 connexion ou envoi SMTP en échec.
 */

require __DIR__ . '/../vendor/autoload.php';

use Epiclub\Engine\Cli\Commande;
use Epiclub\Engine\Cli\ResumeSmtp;
use Epiclub\Engine\Cli\UsageException;
use Epiclub\Process\InstallationService;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Délai de connexion et de réponse du serveur SMTP, en secondes. */
const DELAI_SMTP = 15;

$racine = dirname(__DIR__);

try {
    $cmd = new Commande('tester-smtp', array_slice($argv, 1), ['destinataire'], ['connexion'], $racine . '/var/log/epiclub-cli.log');
} catch (UsageException $e) {
    exit(Commande::echecUsage('tester-smtp', array_slice($argv, 1), $e->getMessage()));
}

if ($cmd->drapeau('aide')) {
    preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $m);
    echo preg_replace('/^ \* ?/m', '', trim($m[1] ?? '')) . "\n";
    exit(Commande::SUCCES);
}

$refus = Commande::verifierContexte(PHP_SAPI, function_exists('posix_geteuid') ? posix_geteuid() : null, $cmd->drapeau('autoriser-root'));
if ($refus !== null) {
    exit($cmd->terminer(Commande::USAGE, $refus));
}

$config = InstallationService::lireConfig($racine . '/.env.local.php');
if ($config === []) {
    exit($cmd->terminer(Commande::PREREQUIS, 'EPIClub n\'est pas installé ici (configuration absente).'));
}
$smtp = ResumeSmtp::depuisConfig($config);
if ($smtp === null) {
    exit($cmd->terminer(Commande::PREREQUIS, 'Aucun serveur SMTP configuré (Configuration système).', ['smtp' => null]));
}

$connexionSeule = $cmd->drapeau('connexion');
$destinataire = null;
if (!$connexionSeule) {
    $destinataire = trim((string) $cmd->option('destinataire', $smtp['expediteur'] ?? ''));
    if ($destinataire === '' || filter_var($destinataire, FILTER_VALIDATE_EMAIL) === false) {
        exit($cmd->terminer(Commande::USAGE, $destinataire === '' ? 'Destinataire manquant (pas d\'expéditeur MAILER_FROM configuré).' : 'Destinataire : adresse email invalide.', ['smtp' => $smtp]));
    }
    if ($smtp['expediteur'] === null) {
        exit($cmd->terminer(Commande::PREREQUIS, 'Adresse expéditeur (MAILER_FROM) manquante.', ['smtp' => $smtp]));
    }
}

$donnees = ['smtp' => $smtp, 'destinataire' => $destinataire, 'envoye' => false];
$serveur = $smtp['hote'] . ($smtp['port'] !== null ? ':' . $smtp['port'] : '');

try {
    $transport = Transport::fromDsn((string) $config['MAILER_DSN']);
} catch (\Throwable $e) {
    exit($cmd->terminer(Commande::PREREQUIS, 'MAILER_DSN illisible : ' . $e->getMessage(), $donnees));
}
if ($transport instanceof SmtpTransport) {
    $flux = $transport->getStream();
    if ($flux instanceof SocketStream) {
        $flux->setTimeout(DELAI_SMTP);
    }
}

$phase = 'Connexion';
try {
    if ($transport instanceof SmtpTransport) {
        $cmd->etape('Connexion à ' . $serveur . '…');
        $transport->start();
        $cmd->etape('Connecté et authentifié' . ($smtp['utilisateur'] !== null ? ' (' . $smtp['utilisateur'] . ')' : '') . '.');
    }
    if ($connexionSeule) {
        if ($transport instanceof SmtpTransport) {
            $transport->stop();
        }
        exit($cmd->terminer(Commande::SUCCES, 'Connexion SMTP réussie (' . $serveur . ').', $donnees));
    }

    $phase = 'Envoi';
    $cmd->etape('Envoi d\'un email de test à ' . $destinataire . '…');
    $email = (new Email())
        ->from(new Address((string) $smtp['expediteur'], $smtp['nom_expediteur'] ?? 'EPIClub'))
        ->to((string) $destinataire)
        ->subject('[TEST] Configuration SMTP EPIClub')
        ->text("Ceci est un email de test, envoyé depuis le serveur qui héberge EPIClub.\n\n"
            . "Si vous recevez ce message, la configuration SMTP de l'instance est correcte.\n\n"
            . 'Serveur : ' . $serveur . "\n"
            . 'Date : ' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('d/m/Y H:i:s') . "\n");
    $transport->send($email);
    if ($transport instanceof SmtpTransport) {
        $transport->stop();
    }
} catch (\Throwable $e) {
    // Le message du transport ne contient pas le mot de passe (Symfony ne
    // cite que l'identifiant) ; il est nécessaire pour corriger la configuration.
    $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
    exit($cmd->terminer(Commande::SMTP_EN_ECHEC, $phase . ' SMTP en échec (' . $serveur . ') : ' . $message, $donnees));
}

$donnees['envoye'] = true;
exit($cmd->terminer(Commande::SUCCES, 'Email de test envoyé à ' . $destinataire . ' (' . $serveur . ').', $donnees));
