<?php

use Epiclub\Controller\AppSetupController;

require __DIR__ . '/../vendor/autoload.php';

// [v1.2.0-beta.2] Filet d'autoload PSR-4 pour le code de l'application.
// L'archive de release génère un classmap « authoritative » : si vendor/
// n'est pas régénéré en même temps que app/ (mise à jour partielle,
// copie manuelle de fichiers), une nouvelle classe Epiclub\… ne serait
// jamais trouvée. Ce chargeur n'intervient qu'en dernier recours.
spl_autoload_register(static function (string $classe): void {
    if (!str_starts_with($classe, 'Epiclub\\')) {
        return;
    }
    $relatif = str_replace('\\', '/', substr($classe, strlen('Epiclub\\')));
    if (str_contains($relatif, '..')) {
        return;
    }
    $fichier = __DIR__ . '/' . $relatif . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});

$envFile = __DIR__ . '/../.env.local.php';

// [SÉCURITÉ — audit P2] Garde-fous avant d'exécuter le fichier de
// configuration : il doit exister, être lisible, commencer par « <?php »
// et retourner un tableau. Un fichier absent ou malformé est ignoré
// (le flux bascule alors vers l'installeur ou le 503).
// Nota : l'exécution d'un include reste intrinsèquement un point
// sensible — le fichier ne doit jamais être accessible via HTTP
// (.htaccess racine, audit C1 bis) ni inscriptible par le processus web
// au-delà des étapes d'installation.
$config = [];
if (is_file($envFile) && is_readable($envFile)) {
    $premiersOctets = file_get_contents($envFile, false, null, 0, 5);
    if ($premiersOctets === '<?php') {
        $contenu = include $envFile;
        if (is_array($contenu)) {
            $config = $contenu;
        }
    }
}

// L'installation est considérée terminée si :
//  - SETUP_COMPLETE est explicitement posé, OU
//  - les paramètres DB sont présents ET un utilisateur existe déjà en
//    base, sauf pour la session qui termine l'assistant (EtatInstallation).
// [SÉCURITÉ — audit P1] Le repli vaut EN MÉMOIRE uniquement : le
// bootstrap ne réécrit JAMAIS .env.local.php (le processus web n'a pas à
// posséder un droit d'écriture sur le fichier de secrets).
$setupComplete = !\Epiclub\Engine\EtatInstallation::installeurRequis(
    $config,
    static function () use ($config): int {
        $pdo = \Epiclub\Process\InstallationService::connexionDepuisConfig($config);
        return (int) $pdo->query("SELECT COUNT(*) FROM utilisateur")->fetchColumn();
    },
    // Session native de l'assistant (setup/includes/helpers.php) ; ouverte
    // seulement dans ce cas rare, et refermée si elle n'est pas celle de
    // l'installateur (la session de l'application démarre ensuite).
    static function (): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $installateur = !empty($_SESSION[\Epiclub\Engine\EtatInstallation::SESSION_ADMIN_CREE]);
        if (!$installateur) {
            session_write_close();
        }
        return $installateur;
    }
);

if (!$setupComplete) {
    if (is_dir(__DIR__ . '/../setup')) {
        require __DIR__ . '/../setup/install.php';
        exit();
    }
    http_response_code(503);
    exit("EPIClub n'est pas installé. Consultez la documentation.");
}

$_ENV = $config;
require __DIR__ . '/../ressources/routes.php';

if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'dev') {
    error_reporting(E_ALL);

    function dd(mixed $variable, $exit = true)
    {
        echo '<pre>';
        var_dump($variable);
        echo '</pre>';
        if ($exit) {
            exit();
        }
    }
}