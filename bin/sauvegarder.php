<?php

declare(strict_types=1);

/**
 * Sauvegarde planifiée d'EPIClub (cron du domaine, console d'hébergement).
 *
 * Usage (sous l'utilisateur du domaine, jamais en root) :
 *
 *   php bin/sauvegarder.php [--garder=N] [--sql-seul] [--quiet] [--json]
 *
 *  - par défaut : archive complète (base + photos, images, factures),
 *    restaurable depuis la page Sauvegarde / restauration :
 *    var/backups/epiclub-sauvegarde-AAAAMMJJ-HHMMSS-planifiee.zip ;
 *  - --sql-seul : dump SQL seul, var/backups/db_AAAA-MM-JJ_HHMMSS.sql ;
 *  - --garder=N : nombre de sauvegardes planifiées conservées (défaut :
 *    BACKUP_RETENTION de .env.local.php, sinon 5). Les exports manuels et
 *    les filets « avant restauration » ne sont pas concernés.
 *
 * Le verrou de l'instance (var/epiclub.lock) empêche de sauvegarder
 * pendant une mise à jour, une restauration ou une installation.
 *
 * Codes de sortie : 0 succès, 1 erreur inattendue, 2 usage,
 * 3 instance non installée ou configuration illisible, 4 verrou occupé,
 * 7 sauvegarde impossible (docs/virtualmin.md § 6.1).
 */

require __DIR__ . '/../vendor/autoload.php';

use Epiclub\Engine\Cli\Commande;
use Epiclub\Engine\Cli\UsageException;
use Epiclub\Engine\DatabaseBackup;
use Epiclub\Engine\MySqlDatabaseDumper;
use Epiclub\Engine\SauvegardeService;
use Epiclub\Engine\VerrouInstance;
use Epiclub\Exception\SauvegardeException;
use Epiclub\Exception\VerrouOccupeException;
use Epiclub\Process\InstallationService;

$racine = dirname(__DIR__);

try {
    $cmd = new Commande(
        'sauvegarder',
        array_slice($argv, 1),
        // fichier-config, dossier : réservés aux tests et aux cas particuliers.
        ['garder', 'fichier-config', 'dossier'],
        ['sql-seul'],
        $racine . '/var/log/epiclub-cli.log'
    );
} catch (UsageException $e) {
    exit(Commande::echecUsage('sauvegarder', array_slice($argv, 1), $e->getMessage()));
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

$garder = $cmd->option('garder');
if ($garder !== null && !preg_match('/^[1-9][0-9]{0,3}$/', $garder)) {
    exit($cmd->terminer(Commande::USAGE, '--garder attend un nombre entier de 1 à 9999.'));
}

$config = InstallationService::lireConfig((string) $cmd->option('fichier-config', $racine . '/.env.local.php'));
if (empty($config['DB_NAME'])) {
    exit($cmd->terminer(Commande::PREREQUIS, 'EPIClub n\'est pas installé ici (configuration absente ou sans base).'));
}
$garder = $garder !== null ? (int) $garder : DatabaseBackup::retention($config['BACKUP_RETENTION'] ?? null);
$dossier = $cmd->option('dossier', $racine . '/var/backups');

try {
    $pdo = InstallationService::connexionDepuisConfig($config);
} catch (\PDOException $e) {
    error_log('[bin/sauvegarder] connexion : ' . $e->getMessage());
    exit($cmd->terminer(Commande::SAUVEGARDE_IMPOSSIBLE, 'Connexion impossible à la base de données.'));
}

try {
    if ($cmd->drapeau('sql-seul')) {
        $verrou = VerrouInstance::pourRacine($racine);
        $sauvegarde = new DatabaseBackup(new MySqlDatabaseDumper($pdo), (string) $dossier);
        $fichier = $verrou->executer('sauvegarde', static function () use ($sauvegarde, $garder): string {
            $fichier = $sauvegarde->dump();
            $sauvegarde->cleanup($garder);
            return $fichier;
        });
        $supprimees = [];
    } else {
        $service = SauvegardeService::pourInstance(
            $racine,
            $pdo,
            (string) ($config['SECRET_KEY'] ?? ''),
            isset($config['PUBLIC_PATH']) ? rtrim((string) $config['PUBLIC_PATH'], '/') . '/images' : null,
            (string) $dossier,
        );
        $fichier = $service->creer(SauvegardeService::TYPE_PLANIFIEE);
        $supprimees = $service->conserver(SauvegardeService::TYPE_PLANIFIEE, $garder);
    }
} catch (VerrouOccupeException $e) {
    exit($cmd->terminer(Commande::VERROU_OCCUPE, $e->getMessage()));
} catch (SauvegardeException $e) {
    $code = $e->getPrevious() instanceof VerrouOccupeException ? Commande::VERROU_OCCUPE : Commande::SAUVEGARDE_IMPOSSIBLE;
    exit($cmd->terminer($code, $e->getMessage()));
} catch (\RuntimeException $e) {
    error_log('[bin/sauvegarder] ' . $e->getMessage());
    exit($cmd->terminer(Commande::SAUVEGARDE_IMPOSSIBLE, $e->getMessage()));
} catch (\Throwable $e) {
    error_log('[bin/sauvegarder] ' . $e::class . ': ' . $e->getMessage());
    exit($cmd->terminer(Commande::ERREUR, 'Erreur inattendue : ' . $e->getMessage()));
}

foreach ($supprimees as $nom) {
    $cmd->etape('Ancienne sauvegarde supprimée : ' . $nom);
}

$taille = (int) filesize($fichier);
exit($cmd->terminer(
    Commande::SUCCES,
    sprintf('Sauvegarde créée : %s (%.1f Mo).', basename($fichier), $taille / 1048576),
    [
        'fichier'    => $fichier,
        'taille'     => $taille,
        'supprimees' => $supprimees,
        'garder'     => $garder,
    ]
));
