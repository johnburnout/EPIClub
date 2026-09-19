<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$envFilePath = __DIR__ . '/../../.env.local.php';
$installationExists = false;

if (file_exists($envFilePath)) {
    $config = @include $envFilePath;
    if (is_array($config) && !empty($config['DB_HOST']) && !empty($config['DB_NAME'])) {
        $installationExists = true;
    }
}

if ($installationExists) {
    http_response_code(403);
    require __DIR__ . '/header.php';
    ?>
    <div class="alert alert-danger">
        <h1>Setup désactivé</h1>
        <p>
            EPIClub est déjà installé sur ce serveur.
            Le dossier <code>setup/</code> doit être supprimé pour des raisons de sécurité.
        </p>
        <p>
            Pour réinstaller, contactez votre administrateur serveur.
            Cette opération doit être effectuée manuellement.
        </p>
        <a href="/" class="btn btn-primary">Retour au site</a>
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit();
}