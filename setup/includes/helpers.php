<?php
// Fichier d'aide pour l'installation
function env($key, $default = null) {
    return $_ENV[$key] ?? $default;
}

function dd($data) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
    die();
}