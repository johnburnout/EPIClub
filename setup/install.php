<?php

use Symfony\Component\HttpFoundation\Request;

if (file_exists(__DIR__ . '/../.env.local.php')) {
    $_ENV = require(__DIR__ . '/../.env.local.php');
}
$request = Request::createFromGlobals();

$step = $_GET['step'] ?? 1;

require __DIR__ . "/steps/step_$step.php";