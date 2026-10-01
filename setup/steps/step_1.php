<?php
/**
 * Étape 1 de l'installateur EPIClub
 *
 * - Vérifie les prérequis système (version PHP, extensions, limites d'upload)
 * - Puis gère la configuration initiale : nom du site et URL racine
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/EnvironmentFileParser.php';

use Epiclub\Engine\EnvironmentFileParser;

// ----------------------------------------------------------------
// VÉRIFICATION DES PRÉREQUIS SYSTÈME
// ----------------------------------------------------------------
$requirements = require __DIR__ . '/../requierements.php';

$prereq_errors   = []; // Bloquants : l'installation ne peut pas continuer
$prereq_warnings = []; // Non bloquants : l'app peut tourner avec limitations

// 1. Version PHP
$requiredPhp = ltrim($requirements['php_version'], '>=');
if (version_compare(PHP_VERSION, $requiredPhp, '<')) {
    $prereq_errors[] = sprintf(
        "PHP %s ou supérieur est requis (version actuelle : %s).",
        $requiredPhp,
        PHP_VERSION
    );
}

// 2. Extensions PHP
foreach ($requirements['php_extensions'] as $ext) {
    if (!extension_loaded($ext)) {
        $prereq_errors[] = sprintf(
            "L'extension PHP « %s » est requise mais non installée. "
            . "Sur Debian/Ubuntu : sudo apt install php-%s",
            $ext,
            str_replace('_', '-', $ext)
        );
    }
}

// 3. Limites d'upload (avertissement seulement : non bloquant)
if (isset($requirements['upload_limits'])) {
    foreach ($requirements['upload_limits'] as $key => $required) {
        $current = (string) ini_get($key);
        if (parseIniSize($current) < parseIniSize($required)) {
            $prereq_warnings[] = sprintf(
                "La limite PHP « %s » est à %s alors que %s est recommandé. "
                . "Les uploads de factures jusqu'à 10 Mo pourraient échouer. "
                . "Ajustez-la dans php.ini, .user.ini, ou depuis votre panneau d'hébergement.",
                $key,
                $current !== '' ? $current : 'non définie',
                $required
            );
        }
    }
}

$canProceed = empty($prereq_errors);

// ----------------------------------------------------------------
// TRAITEMENT DU FORMULAIRE (uniquement si prérequis OK)
// ----------------------------------------------------------------

// 🔧 CRÉER LE FICHIER .env.local.php S'IL N'EXISTE PAS
$envFile = __DIR__ . '/../../.env.local.php';
if (!file_exists($envFile)) {
    file_put_contents($envFile, '<?php return [];');
    chmod($envFile, 0664);
}

$env      = new EnvironmentFileParser();
$existing = $env->load();
$errors   = [];

if ($canProceed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_step1'])) {
    $siteName = trim($_POST['site_name'] ?? '');
    $rootUrl  = trim($_POST['root_url'] ?? '');

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

<?php if (!empty($prereq_errors)): ?>
    <div class="alert alert-danger">
        <h4>❌ Prérequis système non satisfaits</h4>
        <p>L'installation ne peut pas continuer tant que ces problèmes ne sont pas résolus :</p>
        <ul>
            <?php foreach ($prereq_errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="mb-0">
            Après correction, <a href="">rechargez cette page</a>.
        </p>
    </div>
<?php endif; ?>

<?php if (!empty($prereq_warnings)): ?>
    <div class="alert alert-warning">
        <h4>⚠️ Avertissements</h4>
        <ul class="mb-0">
            <?php foreach ($prereq_warnings as $warning): ?>
                <li><?= htmlspecialchars($warning) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($canProceed): ?>
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
<?php else: ?>
    <p class="text-muted">
        Corrigez les prérequis ci-dessus, puis rechargez cette page pour continuer.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>