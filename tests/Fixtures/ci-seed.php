<?php

/**
 * Données minimales pour les tests d'intégration (CI) :
 * compte « admin » / « adminadmin », super-administrateur protégé,
 * attendu par AbstractControllerTestCase::loginAsAdmin().
 *
 * À lancer après bin/migrate.php (les migrations créent le schéma,
 * les fournisseurs et les catégories). Idempotent.
 *
 * Usage : php tests/Fixtures/ci-seed.php
 */

declare(strict_types=1);

$config = require __DIR__ . '/../../.env.local.php';

$pdo = new PDO(
    'mysql:host=' . $config['DB_HOST'] . ';dbname=' . $config['DB_NAME'] . ';charset=utf8mb4',
    $config['DB_USER'],
    $config['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->prepare(
    "INSERT INTO utilisateur (nom, prenom, username, email, password, role, date_creation, est_protege)
     VALUES ('Admin', 'CI', 'admin', 'admin@example.test', :mdp, 'ROLE_SUPER_ADMIN', NOW(), 1)
     ON DUPLICATE KEY UPDATE password = VALUES(password), role = 'ROLE_SUPER_ADMIN'"
)->execute(['mdp' => password_hash('adminadmin', PASSWORD_DEFAULT)]);

echo "Compte admin prêt.\n";
