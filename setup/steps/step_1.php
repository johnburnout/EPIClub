<?php
/**
 * Étape 1 de l'installateur EPIClub
 *
 * - Vérifie les prérequis système (version PHP, extensions, limites d'upload)
 * - Puis gère la configuration initiale : nom du site et URL racine
 */

require_once __DIR__ . '/../includes/helpers.php';

use Epiclub\Engine\EnvironmentFileParser;
use Epiclub\Process\InstallationService;

// ----------------------------------------------------------------
// VÉRIFICATION DES PRÉREQUIS SYSTÈME (ressources/prerequis.php)
// ----------------------------------------------------------------
$prerequis = (new InstallationService(dirname(__DIR__, 2)))->verifierPrerequis();
$prereq_errors   = $prerequis['erreurs'];        // Bloquants : l'installation ne peut pas continuer
$prereq_warnings = $prerequis['avertissements']; // Non bloquants : l'app peut tourner avec limitations

$canProceed = empty($prereq_errors);

// ----------------------------------------------------------------
// TRAITEMENT DU FORMULAIRE (uniquement si prérequis OK)
// ----------------------------------------------------------------

// 🔧 CRÉER LE FICHIER .env.local.php S'IL N'EXISTE PAS
// [#108 - Dette 25] 0600 (owner only) : le fichier contiendra DB_PASS et
// SECRET_KEY — 0664 le rendait lisible par tous les utilisateurs locaux.
$envFile = __DIR__ . '/../../.env.local.php';
InstallationService::preparerFichierConfig($envFile);

// [#106] La classe app (autoloadée) remplace l'ancien doublon setup :
// elle échappe via var_export (#49). Pas de load() : lecture directe.
$env      = new EnvironmentFileParser();
$existing = file_exists($envFile) ? include $envFile : [];
if (!is_array($existing)) {
    $existing = [];
}

$errors   = [];

if ($canProceed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_step1'])) {
    // [#107] CSRF : l'installeur est servi sur toutes les URLs
    // pré-install, un site tiers ne doit pas pouvoir forger ce POST.
    if (!setup_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Erreur de sécurité : jeton CSRF invalide ou expiré. Rechargez la page et réessayez.';
    }

    $siteName = trim($_POST['site_name'] ?? '');
    $rootUrl  = trim($_POST['root_url'] ?? '');

    $errors = array_merge($errors, InstallationService::validerSite($siteName, $rootUrl));

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
        <?= setup_csrf_field() ?>
    </form>
<?php else: ?>
    <p class="text-muted">
        Corrigez les prérequis ci-dessus, puis rechargez cette page pour continuer.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>