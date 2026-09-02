<?php
session_start();
require __DIR__ . '/../includes/header.php'; 
?>

<h1>🎉 Installation terminée !</h1>
<hr>

<div class="alert alert-success">
    <h4>✅ L'application est installée avec succès !</h4>
    <p>Vous pouvez maintenant accéder à votre site.</p>
</div>

<?php if (isset($_SESSION['super_admin_created']) && $_SESSION['super_admin_created']): ?>
    <div class="alert alert-info">
        <h5>🔑 Compte super administrateur</h5>
        <p>Un compte super administrateur a été créé automatiquement :</p>
        <ul>
            <li><strong>Email :</strong> <?= htmlspecialchars($_SESSION['super_admin_email'] ?? 'non défini') ?></li>
            <li><strong>Nom d'utilisateur :</strong> admin</li>
            <li><strong>Mot de passe :</strong> admin</li>
        </ul>
        <p class="text-warning">⚠️ <strong>Important :</strong> Changez ce mot de passe dès votre première connexion !</p>
    </div>
<?php endif; ?>

<div class="alert alert-warning">
    <strong>⚠️ Important :</strong> Avant de continuer, veuillez supprimer le dossier <strong>setup</strong> de votre serveur pour des raisons de sécurité.
</div>

<div class="mt-4">
    <a href="/" class="btn btn-primary btn-lg">🚀 Accéder au site</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>