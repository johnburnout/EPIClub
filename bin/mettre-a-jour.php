<?php

declare(strict_types=1);

/**
 * Mise à jour d'EPIClub vers la dernière version (équivalent de la page
 * « Mise à jour »), pour la console d'hébergement.
 *
 * Usage (sous l'utilisateur du domaine, jamais en root) :
 *
 *   php bin/mettre-a-jour.php [--verifier] [--quiet] [--json]
 *
 *  - sans option : installe la dernière version si elle est plus récente
 *    (rien à faire sinon, code 0) ;
 *  - --verifier : indique la version installée et la version disponible,
 *    sans rien modifier.
 *
 * Canal (stable ou beta) et jeton GitHub facultatif : UPDATE_CHANNEL et
 * GITHUB_TOKEN de .env.local.php, comme la page.
 *
 * Le verrou de l'instance (var/epiclub.lock) empêche de mettre à jour
 * pendant une sauvegarde, une restauration ou une installation.
 *
 * Codes de sortie : 0 succès ou rien à faire, 1 erreur inattendue, 2 usage,
 * 3 instance non installée ou version distante introuvable, 4 verrou
 * occupé, 5 archive rejetée, 7 sauvegarde de la base impossible (rien n'a
 * été modifié), 8 mise à jour interrompue APRÈS modification : il faut
 * intervenir (docs/virtualmin.md § 6.1).
 */

require __DIR__ . '/../vendor/autoload.php';

use Epiclub\Engine\Cli\Commande;
use Epiclub\Engine\Cli\UsageException;
use Epiclub\Engine\DatabaseBackup;
use Epiclub\Engine\GitHubReleaseProvider;
use Epiclub\Engine\MigrationManager;
use Epiclub\Engine\MySqlDatabaseDumper;
use Epiclub\Engine\VerrouInstance;
use Epiclub\Exception\MiseAJourException;
use Epiclub\Exception\VerrouOccupeException;
use Epiclub\Process\InstallationService;
use Epiclub\Process\MiseAJourService;

$racine = dirname(__DIR__);

try {
    $cmd = new Commande('mettre-a-jour', array_slice($argv, 1), [], ['verifier'], $racine . '/var/log/epiclub-cli.log');
} catch (UsageException $e) {
    exit(Commande::echecUsage('mettre-a-jour', array_slice($argv, 1), $e->getMessage()));
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

$dossierTemp = $racine . '/var/tmp/update';
$releases = new GitHubReleaseProvider(
    'johnburnout/EPIClub',
    $dossierTemp . '/latest_release.json',
    3600,
    (string) ($config['UPDATE_CHANNEL'] ?? 'stable'),
    is_string($config['GITHUB_TOKEN'] ?? null) ? $config['GITHUB_TOKEN'] : null,
);

$pdo = null;
$connexion = static function () use (&$pdo, $config): \PDO {
    return $pdo ??= InstallationService::connexionDepuisConfig($config);
};
$service = new MiseAJourService(
    $releases,
    $racine,
    $dossierTemp,
    \Closure::fromCallable($connexion),
    static fn (string $migrationsLivrees): ?string => MiseAJourService::sauvegarderSiMigrationsEnAttente(
        $migrationsLivrees,
        static fn (): array => (new MigrationManager($connexion(), $racine . '/migrations'))->getAppliedVersions(),
        new DatabaseBackup(new MySqlDatabaseDumper($connexion()), $racine . '/var/backups'),
        $config['BACKUP_RETENTION'] ?? null,
    ),
);

if ($cmd->drapeau('verifier')) {
    $etat = $service->verifier();
    if ($etat['disponible'] === null) {
        exit($cmd->terminer(Commande::PREREQUIS, 'Impossible de contacter GitHub (limite de l\'API ou réseau). Version installée : ' . $etat['actuelle'] . '.', $etat));
    }
    $message = $etat['mise_a_jour_disponible']
        ? 'Version ' . $etat['disponible'] . ' disponible (installée : ' . $etat['actuelle'] . ').'
            . ($etat['archive_prete'] ? '' : ' Archive complète en préparation.')
        : 'À jour : ' . $etat['actuelle'] . '.';
    exit($cmd->terminer(Commande::SUCCES, $message, $etat));
}

// La connexion à la base est vérifiée avant toute modification.
try {
    $connexion();
} catch (\PDOException $e) {
    error_log('[bin/mettre-a-jour] connexion : ' . $e->getMessage());
    exit($cmd->terminer(Commande::PREREQUIS, 'Connexion impossible à la base de données.'));
}

$verrou = VerrouInstance::pourRacine($racine);
try {
    $verrou->acquerir('mise-a-jour');
} catch (VerrouOccupeException $e) {
    exit($cmd->terminer(Commande::VERROU_OCCUPE, $e->getMessage() . ' Rien n\'a été modifié.'));
} catch (\RuntimeException $e) {
    exit($cmd->terminer(Commande::PREREQUIS, $e->getMessage()));
}

try {
    $resultat = $service->mettreAJour(
        static function (string $ligne) use ($cmd): void {
            $cmd->etape($ligne);
        },
        false,
        // Le code en cours d'exécution va être remplacé : on charge d'avance
        // les classes dont la fin de la commande a besoin.
        static function (): void {
            foreach ([MigrationManager::class, MiseAJourException::class, Commande::class] as $classe) {
                class_exists($classe);
            }
        },
    );
} catch (MiseAJourException $e) {
    exit($cmd->terminer($e->getCode() ?: Commande::ERREUR, $e->getMessage()));
} catch (\Throwable $e) {
    error_log('[bin/mettre-a-jour] ' . $e::class . ': ' . $e->getMessage());
    exit($cmd->terminer(Commande::ERREUR, 'Erreur inattendue : ' . $e->getMessage()));
} finally {
    $verrou->liberer();
}

exit($cmd->terminer(
    Commande::SUCCES,
    $resultat['a_jour'] ? 'Déjà à jour : ' . $resultat['version'] . '.' : 'Mise à jour réussie : ' . $resultat['ancienne'] . ' → ' . $resultat['version'] . '.',
    $resultat,
));
