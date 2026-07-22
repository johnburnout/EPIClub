<?php
/**
 * Étape 1 de l'installateur EPIClub
 * 
 * Ce fichier gère la configuration initiale : nom du site et URL racine.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/EnvironmentFileParser.php';

$envFile = __DIR__ . '/../../.env.local.php';
$env = new EnvironmentFileParser($envFile);
$existing = $env->load();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_step1'])) {
    $siteName = trim($_POST['site_name'] ?? '');
    $rootUrl  = trim($_POST['root_url'] ?? '');

    $errors = [];
    if (empty($siteName)) {
        $errors[] = "Le nom du site est requis.";
    }
    if (empty($rootUrl)) {
        $errors[] = "L'URL racine du site est requise.";
    } elseif (!filter_var($rootUrl, FILTER_VALIDATE_URL)) {
        $errors[] = "L'URL racine doit être une URL valide (ex: https://mon-site.com).";
    }

    if (empty($errors)) {
        $env->set('SITE_NAME', $siteName);
        $env->set('ROOT_URL', $rootUrl);
        $env->save();

        header('Location: ?step=dbms');
        exit;
    }
}

$siteName = $existing['SITE_NAME'] ?? '';
$rootUrl  = $existing['ROOT_URL'] ?? '';
?>

<?php require __DIR__ . '/../includes/header.php'; ?>

<h1>Configuration initiale</h1>
<hr>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="">
    <div class="mb-3">
        <label for="site_name" class="form-label">Nom du site *</label>
        <input type="text" class="form-control" name="site_name" id="site_name" 
               value="<?= htmlspecialchars($siteName) ?>" required>
    </div>

    <div class="mb-3">
        <label for="root_url" class="form-label">URL racine du site *</label>
        <input type="url" class="form-control" name="root_url" id="root_url" 
               placeholder="https://votre-domaine.com" 
               value="<?= htmlspecialchars($rootUrl) ?>" required>
        <small class="text-muted">L'adresse complète à partir de laquelle votre site sera accessible.</small>
    </div>

    <button type="submit" name="submit_step1" class="btn btn-primary">Suivant</button>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>