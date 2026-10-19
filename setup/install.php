<?php

use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/includes/helpers.php';

if (file_exists(__DIR__ . '/../.env.local.php')) {
    $_ENV = require(__DIR__ . '/../.env.local.php');
}

// [#108 - Dette 27] Garde d'accès : si l'installation est déjà marquée complète,
// l'installeur ne doit plus être accessible (défense en profondeur si le docroot
// inclut le projet au lieu de pointer sur public/).
if (!empty($_ENV['SETUP_COMPLETE'])) {
    http_response_code(403);
    exit('EPIClub est déjà installé. Supprimez le dossier setup/ de votre serveur.');
}

$request = Request::createFromGlobals();

$step = $_GET['step'] ?? '1';

// Liste des étapes autorisées
$allowedSteps = ['1', 'dbms', 'admin', 'smtp', 'club', 'categories', 'final'];

if (!in_array($step, $allowedSteps)) {
    $step = '1';
}

require __DIR__ . "/steps/step_$step.php";