<?php

/**
 * Garde-fou de l'installateur.
 *
 * À inclure au tout début de l'orchestrateur (setup/index.php)
 * ou de chaque étape.
 *
 * Si une installation existe déjà ET que l'utilisateur n'a pas
 * prouvé son identité admin, on redirige vers ?step=confirm_reinstall.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// On ne se garde pas nous-mêmes (sinon boucle infinie)
$currentStep = $_GET['step'] ?? '1';
if ($currentStep === 'confirm_reinstall') {
    return;
}

// Détection : installation existante ?
$envFilePath = __DIR__ . '/../../.env.local.php';
$installationExists = false;

if (file_exists($envFilePath)) {
    $config = @include $envFilePath;
    if (
        is_array($config)
        && !empty($config['DB_HOST'])
        && !empty($config['DB_NAME'])
        && !empty($config['DB_USER'])
    ) {
        $installationExists = true;
    }
}

// Si installation existante et pas encore autorisé → on redirige
if ($installationExists && empty($_SESSION['setup_reinstall_authorized'])) {
    header('Location: ?step=confirm_reinstall');
    exit();
}