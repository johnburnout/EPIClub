<?php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Nettoyer les flags éventuels
    unset(
        $_SESSION['setup_reinstall_authorized'],
        $_SESSION['setup_reinstall_authorized_at'],
        $_SESSION['setup_reinstall_authorized_by']
    );

    // Marquer l'installation comme terminée
    $envFile = __DIR__ . '/../../.env.local.php';
    if (file_exists($envFile)) {
        \Epiclub\Process\InstallationService::marquerTerminee($envFile);
    }

    require __DIR__ . '/../includes/header.php';
?>

<h1>🎉 Installation terminée !</h1>
<hr>

<div class="alert alert-success">
    <h4>✅ L'application est installée avec succès !</h4>
    <p>Vous pouvez maintenant accéder à votre site.</p>
</div>

<div class="alert alert-danger">
    <h4>⚠️ Action obligatoire</h4>
    <p>
        <strong>Supprimez immédiatement le dossier <code>setup/</code></strong>
        de votre serveur (via FTP, SSH, ou votre panneau d'administration).
    </p>
    <p>
        Tant que ce dossier existe, EPIClub est vulnérable.
    </p>
    <p>
        En cas d'oubli, l'application affichera une erreur 503 sur les pages
        non installées.
    </p>
</div>

<div class="mt-4">
    <a href="/" class="btn btn-primary btn-lg">🚀 Accéder au site</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>