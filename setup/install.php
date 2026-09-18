<?php

use Symfony\Component\HttpFoundation\Request;

if (file_exists(__DIR__ . '/../.env.local.php')) {
    $_ENV = require(__DIR__ . '/../.env.local.php');
}
$request = Request::createFromGlobals();

$step = $_GET['step'] ?? '1';

// Liste des étapes autorisées
$allowedSteps = ['1', 'dbms', 'admin', 'smtp', 'club', 'final', 'confirm_reinstall'];

if (!in_array($step, $allowedSteps)) {
    $step = '1';
}

// Garde-fou : bloque l'accès au setup si une installation existe déjà
// et que l'utilisateur n'a pas prouvé son identité admin.
require __DIR__ . '/includes/guard.php';

require __DIR__ . "/steps/step_$step.php";