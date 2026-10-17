<?php
// [#109] Fixture de configuration des tests — commitée.
// Remplace le .env.local.php gitignored pour la suite de tests
// (constante EPICLUB_CONFIG_FILE définie par tests/bootstrap.php).
// Les clés DB pointent sur la base de dev locale : iso-comportement
// avec la situation antérieure (les tests lisaient le .env.local.php réel).
return [
    'DB_HOST'       => '127.0.0.1',
    'DB_NAME'       => 'epiclub',
    'DB_USER'       => 'root',
    'DB_PASS'       => '',
    'SECRET_KEY'    => str_repeat('ab', 32),  // 32 bytes pour AES-256
    'CIPHER_METHOD' => 'AES-256-CBC',
    'ROOT_URL'      => 'http://localhost',
];
