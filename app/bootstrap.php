<?php

use Epiclub\Controller\AppSetupController;

require __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env.local.php';
$config = file_exists($envFile) ? (include $envFile) : [];
if (!is_array($config)) {
    $config = [];
}

// L'installation est considérée terminée quand SETUP_COMPLETE est posé
// ET que les paramètres DB sont présents.
$setupComplete = !empty($config['SETUP_COMPLETE'])
    && !empty($config['DB_HOST'])
    && !empty($config['DB_NAME']);

if (!$setupComplete) {
    if (is_dir(__DIR__ . '/../setup')) {
        require __DIR__ . '/../setup/install.php';
        exit();
    }
    http_response_code(503);
    exit("EPIClub n'est pas installé et le dossier setup/ est introuvable. Consultez la documentation.");
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