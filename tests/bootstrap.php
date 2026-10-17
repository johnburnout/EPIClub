<?php

/**
 * [#109] Bootstrap PHPUnit — seam de configuration pour les tests.
 *
 * Définit EPICLUB_CONFIG_FILE : chemin de la config utilisée par
 * Epiclub\Engine\ConfigFile::defaultPath() quand la constante existe.
 * La suite Unit devient ainsi indépendante du `.env.local.php` gitignored
 * (68 tests lisaient ce fichier en construction des Managers/ConfigProvider).
 *
 * La fixture commitée (tests/Fixtures/env.test.php) porte les clés DB vers
 * la base de dev locale — iso-comportement avec la situation antérieure.
 */

declare(strict_types=1);

define('EPICLUB_CONFIG_FILE', __DIR__ . '/Fixtures/env.test.php');

require dirname(__DIR__) . '/vendor/autoload.php';
