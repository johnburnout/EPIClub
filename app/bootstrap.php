<?php 

if (!file_exists(__DIR__ . '/../.env.local.php')) {
    throw new \Exception("Le fichier environement est introuvable.", 1);
}

$_ENV = require __DIR__ . '/../.env.local.php';

require __DIR__ . "/../includes/session.php";
require __DIR__ . '/../includes/database_connection.php';

$db = db();