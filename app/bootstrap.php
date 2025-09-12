<?php

use Epiclub\Controller\AppSetupController;

require __DIR__ .'/../vendor/autoload.php';

if (is_dir(__DIR__ . '/../setup')) {
    # throw new \Exception("L'application ne semble pas être installée correctement. Veuillez lire la documentation sur l'insatllation", 1);
    # header('Location: /setup');
    require __DIR__ . '/../setup/install.php';
    exit();
}

$_ENV = require __DIR__ . '/../.env.local.php';
require __DIR__ . '/ressources/routes.php';

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
