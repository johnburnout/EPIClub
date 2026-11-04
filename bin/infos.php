<?php

declare(strict_types=1);

/**
 * Coordonnées du club (page Gestion du club), en lecture seule, pour la
 * console d'hébergement.
 *
 * Usage (sous l'utilisateur du domaine, jamais en root) :
 *
 *   php bin/infos.php [--json]
 *
 * Renvoie le nom, l'activité, l'email et le téléphone du club, et le
 * résumé de la configuration SMTP (serveur, port, identifiant, expéditeur ;
 * jamais le mot de passe ; null si aucun SMTP). Rien n'est modifié, rien
 * n'est journalisé (la console l'appelle à chaque affichage de la fiche de
 * l'instance). Pour tester le SMTP : bin/tester-smtp.php.
 *
 * Codes de sortie : 0 succès, 1 erreur inattendue, 2 usage, 3 instance non
 * installée ou base inaccessible.
 */

require __DIR__ . '/../vendor/autoload.php';

use Epiclub\Engine\Cli\Commande;
use Epiclub\Engine\Cli\ResumeSmtp;
use Epiclub\Engine\Cli\UsageException;
use Epiclub\Process\InstallationService;

$racine = dirname(__DIR__);

try {
    $cmd = new Commande('infos', array_slice($argv, 1), [], []);
} catch (UsageException $e) {
    exit(Commande::echecUsage('infos', array_slice($argv, 1), $e->getMessage()));
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
if (empty($config['DB_NAME'])) {
    exit($cmd->terminer(Commande::PREREQUIS, 'EPIClub n\'est pas installé ici (configuration absente ou sans base).'));
}

try {
    $pdo = InstallationService::connexionDepuisConfig($config);
    $club = $pdo->query('SELECT nom, activite, email, phone FROM club WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    error_log('[bin/infos] ' . $e->getMessage());
    exit($cmd->terminer(Commande::PREREQUIS, 'Lecture impossible de la base de données.'));
}

$texte = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
$infos = [
    'nom'       => $texte($club['nom'] ?? null),
    'activite'  => $texte($club['activite'] ?? null),
    'email'     => $texte($club['email'] ?? null),
    'telephone' => $texte($club['phone'] ?? null),
];

exit($cmd->terminer(Commande::SUCCES, $infos['nom'] !== null ? 'Club : ' . $infos['nom'] . '.' : 'Fiche du club vide.', ['club' => $infos, 'smtp' => ResumeSmtp::depuisConfig($config)]));
