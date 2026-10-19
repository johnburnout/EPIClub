<?php

use Epiclub\Controller\AppSetupController;

require __DIR__ . '/../vendor/autoload.php';

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
//  - les paramètres DB sont présents ET un utilisateur existe déjà en base
$setupComplete = !empty($config['SETUP_COMPLETE']);

if (!$setupComplete && !empty($config['DB_HOST']) && !empty($config['DB_NAME'])) {
    // Fallback : détecter une installation existante sans flag SETUP_COMPLETE
    try {
        $pdo = new \PDO(
            'mysql:host=' . $config['DB_HOST']
            . ';dbname=' . $config['DB_NAME']
            . ';charset=utf8mb4',
            $config['DB_USER'],
            $config['DB_PASS'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $count = (int) $pdo->query("SELECT COUNT(*) FROM utilisateur")->fetchColumn();
        if ($count > 0) {
            // [SÉCURITÉ — audit P1] Installation existante détectée :
            // on marque le setup complet EN MÉMOIRE uniquement. Le
            // bootstrap ne doit JAMAIS réécrire .env.local.php (le
            // processus web n'a pas à posséder un droit d'écriture sur
            // le fichier de secrets — seul l'installeur le crée).
            $setupComplete = true;
        }
    } catch (\Throwable $e) {
        // Table utilisateur absente ou DB inaccessible → on reste sur le setup
        error_log('[bootstrap] SETUP_COMPLETE fallback failed: ' . $e->getMessage());
    }
}

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