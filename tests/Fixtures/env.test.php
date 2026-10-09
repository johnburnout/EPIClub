<?php
return [
    'SECRET_KEY'    => str_repeat('ab', 32),  // 32 bytes pour AES-256
    'CIPHER_METHOD' => 'AES-256-CBC',
    'ROOT_URL'      => 'http://localhost',
];