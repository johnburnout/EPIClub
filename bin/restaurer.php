<?php

declare(strict_types=1);

/**
 * Restauration d'une sauvegarde d'EPIClub (équivalent du bouton
 * « Restaurer » de la page Sauvegarde / restauration), pour la console
 * d'hébergement.
 *
 * Usage (sous l'utilisateur du domaine, jamais en root) :
 *
 *   php bin/restaurer.php --nom=epiclub-sauvegarde-AAAAMMJJ-HHMMSS.zip [--accepter-non-signee] [--quiet] [--json]
 *
 * L'archive doit se trouver dans var/backups (nom seul, jamais un chemin).
 * Base ET fichiers sont remplacés. L'état courant est d'abord conservé dans
 * une sauvegarde « avant restauration » ; en cas d'échec, il est rétabli
 * automatiquement. Les migrations manquantes (archive d'une version
 * antérieure) sont appliquées ensuite.
 *
 * L'archive doit être signée avec la SECRET_KEY de l'installation (archives
 * créées depuis la v2.7.1). --accepter-non-signee accepte une archive SANS
 * signature (créée par une version antérieure) : à réserver à une archive
 * dont vous connaissez la provenance, car son SQL est exécuté tel quel. Une
 * signature fausse est toujours refusée.
 *
 * Codes de sortie : 0 succès, 1 erreur inattendue, 2 usage, 3 instance non
 * installée, 4 verrou occupé, 7 restauration impossible (rien n'a été
 * modifié, ou état précédent rétabli), 8 échec ET état précédent non
 * rétabli : il faut intervenir (docs/virtualmin.md § 6.1).
 */

require __DIR__ . '/../vendor/autoload.php';

use Epiclub\Engine\Cli\Commande;
use Epiclub\Engine\Cli\UsageException;
use Epiclub\Engine\MigrationManager;
use Epiclub\Engine\SauvegardeService;
use Epiclub\Exception\SauvegardeException;
use Epiclub\Exception\VerrouOccupeException;
use Epiclub\Process\InstallationService;

$racine = dirname(__DIR__);

try {
    $cmd = new Commande('restaurer', array_slice($argv, 1), ['nom'], ['accepter-non-signee'], $racine . '/var/log/epiclub-cli.log');
} catch (UsageException $e) {
    exit(Commande::echecUsage('restaurer', array_slice($argv, 1), $e->getMessage()));
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

$nom = (string) $cmd->option('nom', '');
if ($nom === '' || basename($nom) !== $nom) {
    exit($cmd->terminer(Commande::USAGE, '--nom attend le nom d\'une archive de var/backups (sans chemin).'));
}

$config = InstallationService::lireConfig($racine . '/.env.local.php');
if (empty($config['DB_NAME'])) {
    exit($cmd->terminer(Commande::PREREQUIS, 'EPIClub n\'est pas installé ici (configuration absente ou sans base).'));
}

try {
    $pdo = InstallationService::connexionDepuisConfig($config);
} catch (\PDOException $e) {
    error_log('[bin/restaurer] connexion : ' . $e->getMessage());
    exit($cmd->terminer(Commande::PREREQUIS, 'Connexion impossible à la base de données.'));
}

$service = SauvegardeService::pourInstance(
    $racine,
    $pdo,
    (string) ($config['SECRET_KEY'] ?? ''),
    isset($config['PUBLIC_PATH']) ? rtrim((string) $config['PUBLIC_PATH'], '/') . '/images' : null,
);

// Une restauration peut être longue (photos, factures).
@set_time_limit(0);

try {
    $cmd->etape('Restauration de ' . $nom . '…');
    $resultat = $service->restaurer(
        $service->chemin($nom),
        static fn (): array => (new MigrationManager($pdo, $racine . '/migrations'))->migrate(),
        $cmd->drapeau('accepter-non-signee'),
    );
} catch (VerrouOccupeException $e) {
    exit($cmd->terminer(Commande::VERROU_OCCUPE, $e->getMessage()));
} catch (SauvegardeException $e) {
    $code = match (true) {
        $e->getPrevious() instanceof VerrouOccupeException       => Commande::VERROU_OCCUPE,
        $e->getCode() === SauvegardeException::ETAT_NON_RETABLI => Commande::INTERROMPUE,
        default                                                 => Commande::SAUVEGARDE_IMPOSSIBLE,
    };
    exit($cmd->terminer($code, $e->getMessage()));
} catch (\Throwable $e) {
    error_log('[bin/restaurer] ' . $e::class . ': ' . $e->getMessage());
    exit($cmd->terminer(Commande::ERREUR, 'Erreur inattendue : ' . $e->getMessage()));
}

$m = $resultat['manifeste'];
$cmd->etape('État précédent conservé dans ' . $resultat['filet'] . '.');
foreach ($resultat['migrations'] as $v) {
    $cmd->etape('Migration appliquée : ' . $v);
}

exit($cmd->terminer(
    Commande::SUCCES,
    sprintf(
        'Restauration terminée : sauvegarde du %s (version %s), %d fichier(s).',
        date('d/m/Y à H:i', strtotime((string) ($m['cree_le'] ?? '')) ?: time()),
        (string) ($m['version'] ?? '?'),
        (int) ($m['nb_fichiers'] ?? 0),
    ),
    [
        'nom'        => $nom,
        'filet'      => $resultat['filet'],
        'version'    => $m['version'] ?? null,
        'migrations' => $resultat['migrations'],
    ]
));
