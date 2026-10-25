<?php

declare(strict_types=1);

/**
 * Installation non interactive d'EPIClub (équivalent de l'assistant web).
 *
 * Usage (sous l'utilisateur du domaine, jamais en root) :
 *
 *   EPICLUB_DB_PASS=… EPICLUB_ADMIN_PASSWORD=… php bin/installer.php \
 *     --site-name="EPIClub – Club X" --root-url=https://epi.club-x.fr \
 *     --db-host=localhost --db-name=clubx_epiclub --db-user=clubx \
 *     --admin-username=jdupont --admin-email=j.dupont@club-x.fr \
 *     --admin-nom=Dupont --admin-prenom=Jean \
 *     --club-nom="Club X" --club-email=contact@club-x.fr --activite=Escalade
 *
 * Options facultatives :
 *   --club-description=…  --club-telephone=…  --sans-categories
 *   --smtp-hote=…  --smtp-port=587  --smtp-utilisateur=…
 *   --admin-mot-de-passe-aleatoire   (mot de passe généré, affiché une fois)
 *   --quiet  --json  --autoriser-root  --aide
 *
 * Secrets : jamais en argument (visibles dans « ps »). Variables
 * d'environnement EPICLUB_DB_PASS, EPICLUB_ADMIN_PASSWORD,
 * EPICLUB_SMTP_PASSWORD, ou fichiers --db-pass-fichier=…,
 * --admin-password-fichier=…, --smtp-password-fichier=….
 *
 * Codes de sortie : 0 succès, 1 erreur inattendue, 2 usage, 3 prérequis,
 * 4 verrou occupé, 8 installation interrompue, 9 déjà installé
 * (docs/virtualmin.md § 6.1).
 */

require __DIR__ . '/../vendor/autoload.php';

use Epiclub\Engine\Cli\Commande;
use Epiclub\Engine\Cli\UsageException;
use Epiclub\Engine\VerrouInstance;
use Epiclub\Exception\InstallationException;
use Epiclub\Exception\VerrouOccupeException;
use Epiclub\Process\InstallationService;

$racine = dirname(__DIR__);

$optionsValeur = [
    'site-name', 'root-url',
    'db-host', 'db-name', 'db-user', 'db-pass-fichier',
    'admin-username', 'admin-email', 'admin-nom', 'admin-prenom', 'admin-password-fichier',
    'club-nom', 'club-email', 'activite', 'club-description', 'club-telephone',
    'smtp-hote', 'smtp-port', 'smtp-utilisateur', 'smtp-password-fichier',
    // Réservé aux tests et aux cas particuliers : autre emplacement de .env.local.php.
    'fichier-config',
];
$drapeaux = ['sans-categories', 'admin-mot-de-passe-aleatoire'];

try {
    $cmd = new Commande('installer', array_slice($argv, 1), $optionsValeur, $drapeaux, $racine . '/var/log/epiclub-cli.log');
} catch (UsageException $e) {
    exit(Commande::echecUsage('installer', array_slice($argv, 1), $e->getMessage()));
}

if ($cmd->drapeau('aide')) {
    // Le bloc de documentation en tête de ce fichier.
    preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $m);
    echo preg_replace('/^ \* ?/m', '', trim($m[1] ?? '')) . "\n";
    exit(Commande::SUCCES);
}

$refus = Commande::verifierContexte(PHP_SAPI, function_exists('posix_geteuid') ? posix_geteuid() : null, $cmd->drapeau('autoriser-root'));
if ($refus !== null) {
    exit($cmd->terminer(Commande::USAGE, $refus));
}

/**
 * Secret : variable d'environnement, sinon fichier (première ligne).
 */
$secret = static function (string $variable, ?string $fichier) use ($cmd): ?string {
    $valeur = getenv($variable);
    if (is_string($valeur) && $valeur !== '') {
        return $valeur;
    }
    if ($fichier === null) {
        return null;
    }
    if (!is_file($fichier) || !is_readable($fichier)) {
        throw new UsageException('Fichier de secret illisible : ' . $fichier);
    }
    return rtrim((string) strtok((string) file_get_contents($fichier), "\n"), "\r");
};

try {
    $motDePasseAdmin = $secret('EPICLUB_ADMIN_PASSWORD', $cmd->option('admin-password-fichier'));
    if ($motDePasseAdmin === null && !$cmd->drapeau('admin-mot-de-passe-aleatoire')) {
        throw new UsageException(
            'Mot de passe du super-administrateur absent : EPICLUB_ADMIN_PASSWORD, '
            . '--admin-password-fichier=… ou --admin-mot-de-passe-aleatoire.'
        );
    }

    $smtp = null;
    if (($hote = (string) $cmd->option('smtp-hote', '')) !== '') {
        $smtp = [
            'hote'         => $hote,
            'port'         => (string) $cmd->option('smtp-port', '587'),
            'utilisateur'  => (string) $cmd->option('smtp-utilisateur', ''),
            'mot_de_passe' => (string) $secret('EPICLUB_SMTP_PASSWORD', $cmd->option('smtp-password-fichier')),
        ];
    }

    $parametres = [
        'site_name' => (string) $cmd->option('site-name', ''),
        'root_url'  => (string) $cmd->option('root-url', ''),
        'db_host'   => (string) $cmd->option('db-host', 'localhost'),
        'db_name'   => (string) $cmd->option('db-name', ''),
        'db_user'   => (string) $cmd->option('db-user', ''),
        'db_pass'   => (string) $secret('EPICLUB_DB_PASS', $cmd->option('db-pass-fichier')),
        'admin'     => [
            'nom'      => (string) $cmd->option('admin-nom', ''),
            'prenom'   => (string) $cmd->option('admin-prenom', ''),
            'username' => (string) $cmd->option('admin-username', ''),
            'email'    => (string) $cmd->option('admin-email', ''),
            'password' => $motDePasseAdmin,
        ],
        'club'      => [
            'nom'         => (string) $cmd->option('club-nom', ''),
            'email'       => (string) $cmd->option('club-email', ''),
            'activite'    => (string) $cmd->option('activite', ''),
            'description' => (string) $cmd->option('club-description', ''),
            'phone'       => (string) $cmd->option('club-telephone', ''),
        ],
        'categories' => !$cmd->drapeau('sans-categories'),
        'smtp'       => $smtp,
    ];
} catch (UsageException $e) {
    exit($cmd->terminer(Commande::USAGE, $e->getMessage()));
}

$fichierConfig = (string) $cmd->option('fichier-config', $racine . '/.env.local.php');
$verrou = VerrouInstance::pourRacine($racine);

try {
    $verrou->acquerir('installation');
} catch (VerrouOccupeException $e) {
    exit($cmd->terminer(Commande::VERROU_OCCUPE, $e->getMessage()));
} catch (\RuntimeException $e) {
    exit($cmd->terminer(Commande::PREREQUIS, $e->getMessage()));
}

try {
    $resultat = (new InstallationService($racine))->installer(
        $parametres,
        $fichierConfig,
        static function (string $ligne) use ($cmd): void {
            $cmd->etape($ligne);
        }
    );
} catch (InstallationException $e) {
    exit($cmd->terminer($e->getCode() ?: Commande::ERREUR, $e->getMessage(), [], $e->details()));
} catch (\Throwable $e) {
    error_log('[bin/installer] ' . $e::class . ': ' . $e->getMessage());
    exit($cmd->terminer(Commande::ERREUR, 'Erreur inattendue : ' . $e->getMessage()));
} finally {
    $verrou->liberer();
}

$motDePasse = $resultat['mot_de_passe_genere'];
if ($motDePasse !== null && !$cmd->enJson()) {
    // Affiché une seule fois, jamais journalisé.
    echo 'Mot de passe généré pour ' . $parametres['admin']['username'] . ' (affiché une seule fois) : ' . $motDePasse . "\n";
}

exit($cmd->terminer(Commande::SUCCES, 'EPIClub est installé.', [
    'migrations'          => $resultat['migrations'],
    'admin'               => $parametres['admin']['username'],
    'mot_de_passe_genere' => $motDePasse,
    'categories'          => $resultat['categories'],
    'fichier_config'      => $fichierConfig,
]));
