<?php

// Router pour le serveur de dev php -S :
// laisse le serveur servir les fichiers statiques (assets, images),
// toutes les autres URLs sont déléguées à index.php.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

require __DIR__ . '/index.php';