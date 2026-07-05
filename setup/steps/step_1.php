<?php
/**
 * Étape 1 de l'installateur EPIClub
 * 
 * Ce fichier gère la configuration initiale : nom du site, email admin, mot de passe,
 * et maintenant l'URL racine (ROOT_URL).
 */

// Chargement des helpers et du parser d'environnement
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/EnvironmentFileParser.php';

// Vérifier que le fichier .env.local.php n'existe pas déjà (ou qu'on est en réinstallation)
$envFile = __DIR__ . '/../.env.local.php';
$env = new EnvironmentFileParser($envFile);
$existing = $env->load();

// Si le formulaire est soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_step1'])) {
    // Récupération des champs
    $siteName    = trim($_POST['site_name'] ?? '');
    $adminEmail  = trim($_POST['admin_email'] ?? '');
    $adminPass   = $_POST['admin_password'] ?? '';
    $adminPassConfirm = $_POST['admin_password_confirm'] ?? '';
    $rootUrl     = trim($_POST['root_url'] ?? '');

    // Validation basique
    $errors = [];
    if (empty($siteName)) {
        $errors[] = "Le nom du site est requis.";
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'adresse email administrateur n'est pas valide.";
    }
    if (strlen($adminPass) < 8) {
        $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
    }
    if ($adminPass !== $adminPassConfirm) {
        $errors[] = "Les mots de passe ne correspondent pas.";
    }
    if (empty($rootUrl)) {
        $errors[] = "L'URL racine du site est requise.";
    } elseif (!filter_var($rootUrl, FILTER_VALIDATE_URL)) {
        $errors[] = "L'URL racine doit être une URL valide (ex: https://mon-site.com).";
    }

    if (empty($errors)) {
        // Enregistrement des variables dans .env.local.php
        $env->set('SITE_NAME', $siteName);
        $env->set('ADMIN_EMAIL', $adminEmail);
        // On hash le mot de passe (à adapter selon la méthode utilisée par EPIClub)
        $env->set('ADMIN_PASSWORD', password_hash($adminPass, PASSWORD_DEFAULT));
        $env->set('ROOT_URL', $rootUrl);

        // Sauvegarder le fichier
        $env->save();

        // Rediriger vers l'étape suivante (par ex. step_2.php)
        header('Location: step_2.php');
        exit;
    }
}

// Si des données existent déjà, on les pré-remplit (utile en cas d'erreur)
$siteName    = $existing['SITE_NAME']    ?? '';
$adminEmail  = $existing['ADMIN_EMAIL']  ?? '';
$rootUrl     = $existing['ROOT_URL']     ?? '';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Installation d'EPIClub - Étape 1</title>
    <link rel="stylesheet" href="assets/install.css">
</head>
<body>
    <div class="container">
        <h1>Configuration initiale</h1>
        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="field">
                <label for="site_name">Nom du site *</label>
                <input type="text" name="site_name" id="site_name" 
                       value="<?= htmlspecialchars($siteName) ?>" required>
            </div>

            <div class="field">
                <label for="admin_email">Email administrateur *</label>
                <input type="email" name="admin_email" id="admin_email" 
                       value="<?= htmlspecialchars($adminEmail) ?>" required>
            </div>

            <div class="field">
                <label for="admin_password">Mot de passe administrateur *</label>
                <input type="password" name="admin_password" id="admin_password" required>
                <small>Minimum 8 caractères</small>
            </div>

            <div class="field">
                <label for="admin_password_confirm">Confirmer le mot de passe *</label>
                <input type="password" name="admin_password_confirm" id="admin_password_confirm" required>
            </div>

            <!-- NOUVEAU CHAMP : URL racine -->
            <div class="field">
                <label for="root_url">URL racine du site *</label>
                <input type="url" name="root_url" id="root_url" 
                       placeholder="https://votre-domaine.com" 
                       value="<?= htmlspecialchars($rootUrl) ?>" required>
                <small>L'adresse complète à partir de laquelle votre site sera accessible.</small>
            </div>

            <div class="actions">
                <button type="submit" name="submit_step1">Suivant</button>
            </div>
        </form>
    </div>
</body>
</html>