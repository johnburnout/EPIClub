<?php


return [
    'php_version' => '>=8.4',
    'php_extensions' => [
        'gd',          // QR codes, traitement d'images
        'fileinfo',    // détection MIME des factures uploadées
        'pdo_mysql',   // connexion BDD (AbstractManager)
        'zip',         // extraction des releases GitHub (AppUpdateController)
    ],
    'dbms' => [
        'mysql' => '5.7',
        'mariadb' => '10.5'
    ],

    // Limites d'upload requises pour les factures d'acquisition.
    // L'application promet jusqu'à 10 Mo ; on demande 12 Mo pour laisser
    // une marge (le corps du POST contient aussi les autres champs et le CSRF).
    'upload_limits' => [
        'post_max_size'       => '12M',
        'upload_max_filesize' => '12M',
    ],
];