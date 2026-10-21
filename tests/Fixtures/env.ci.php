<?php
// Configuration de l'application pour la CI GitHub (workflow Tests) :
// copiée en .env.local.php avant les tests. Base MySQL du service de
// la CI (root sans mot de passe, base « epiclub »), mêmes valeurs que
// tests/Fixtures/env.test.php. Aucun secret réel.
return [
    'DB_HOST'        => '127.0.0.1',
    'DB_NAME'        => 'epiclub',
    'DB_USER'        => 'root',
    'DB_PASS'        => '',
    'SECRET_KEY'     => str_repeat('ab', 32),
    'CIPHER_METHOD'  => 'AES-256-CBC',
    'ROOT_URL'       => 'http://localhost:8000',
    'MAILER_DSN'     => 'null://null',
    'MAILER_FROM'    => 'ci@example.test',
    'MAILER_NAME'    => 'EPIClub CI',
    'UPDATE_CHANNEL' => 'stable',
    'SETUP_COMPLETE' => true,
];
