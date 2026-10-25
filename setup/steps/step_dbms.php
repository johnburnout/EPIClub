<?php

/**
 * Étape DBMS de l'installateur EPIClub
 *
 * Sécurité :
 *  - validation stricte des identifiants (regex)
 *  - AUCUNE suppression de tables depuis le navigateur
 *  - si la base contient des tables → erreur, suppression manuelle requise
 *  - pas de réaffichage du mot de passe en cas d'erreur
 *  - parsing SQL délégué au MigrationManager
 *  - logique partagée avec bin/installer.php (InstallationService)
 */

use Epiclub\Exception\InstallationException;
use Epiclub\Process\InstallationService;

$db_params = [
    'db_host' => 'localhost',
    'db_name' => 'epiclub',
    'db_user' => '',
    'db_pass' => ''
];
$errors = [];

// Détection d'une installation existante pour pré-remplir
$envFilePath = __DIR__ . '/../../.env.local.php';
if (file_exists($envFilePath)) {
    $existingConfig = @include $envFilePath;
    if (is_array($existingConfig) && !empty($existingConfig['DB_NAME'])) {
        $db_params['db_host'] = $existingConfig['DB_HOST'] ?? $db_params['db_host'];
        $db_params['db_name'] = $existingConfig['DB_NAME'];
        $db_params['db_user'] = $existingConfig['DB_USER'] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [#107] CSRF : rejette les POST forgés cross-site pendant la
    // fenêtre d'installation (credentials BDD écrits dans .env.local.php).
    if (!setup_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Erreur de sécurité : jeton CSRF invalide ou expiré. Rechargez la page et réessayez.';
    }

    $db_host = trim($_POST['db_host'] ?? '');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';

    // ---- Validation stricte (InstallationService) ----
    $errors = array_merge($errors, InstallationService::validerBase($db_host, $db_name, $db_user));

    // ---- Connexion et installation ----
    if (empty($errors)) {
        try {
            $pdo = InstallationService::connexion($db_host, $db_user, $db_pass);

            // Base créée si besoin ; refusée si elle contient déjà des
            // tables (suppression manuelle requise) ; puis migrations.
            $applied = (new InstallationService(dirname(__DIR__, 2)))->installerBase(
                $pdo,
                $db_name,
                function (string $ligne) {
                    error_log('[setup/dbms] ' . $ligne);
                }
            );

            error_log(sprintf(
                '[setup/dbms] Installation OK : %d migration(s) appliquée(s).',
                count($applied)
            ));

            // ---- Écriture de la config ----
            InstallationService::ecrireConfig($envFilePath, [
                'DB_HOST' => $db_host,
                'DB_NAME' => $db_name,
                'DB_USER' => $db_user,
                'DB_PASS' => $db_pass,
            ]);

            header('Location: ?step=admin');
            exit();
        } catch (InstallationException $e) {
            // Base non vide, nom invalide : message prévu pour l'utilisateur.
            error_log('[setup/dbms] Installation refusée : ' . $e->getMessage());
            $errors[] = htmlspecialchars($e->getMessage());
        } catch (\PDOException $e) {
            error_log('[setup/dbms] PDO error: ' . $e->getMessage());
            $errors[] = "Connexion impossible à la base de données. " .
                        "Vérifiez l'hôte, le nom d'utilisateur et le mot de passe.";
        } catch (\Throwable $e) {
            error_log('[setup/dbms] Error: ' . $e->getMessage());
            $errors[] = "Erreur lors de l'installation : " . htmlspecialchars($e->getMessage());
        }
    }

    // Repopuler le formulaire (sans le mot de passe)
    $db_params = [
        'db_host' => $db_host,
        'db_name' => $db_name,
        'db_user' => $db_user,
        'db_pass' => '',
    ];
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Base de données</h1>
<hr>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= $error ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" autocomplete="off">
    <div class="mb-3">
        <label for="db_host" class="form-label">Adresse serveur *</label>
        <input type="text" class="form-control" name="db_host" id="db_host"
               value="<?= htmlspecialchars($db_params['db_host']); ?>"
               placeholder="localhost ou 127.0.0.1:3306" required>
        <small class="text-muted">Hôte MySQL. Peut inclure un port (ex: localhost:3306).</small>
    </div>

    <div class="mb-3">
        <label for="db_name" class="form-label">Nom de la base *</label>
        <input type="text" class="form-control" name="db_name" id="db_name"
               value="<?= htmlspecialchars($db_params['db_name']); ?>"
               pattern="[a-zA-Z0-9_]+" maxlength="64" required>
        <small class="text-muted">Lettres, chiffres et underscores uniquement.</small>
    </div>

    <div class="mb-3">
        <label for="db_user" class="form-label">Nom d'utilisateur *</label>
        <input type="text" class="form-control" name="db_user" id="db_user"
               value="<?= htmlspecialchars($db_params['db_user']); ?>" required>
    </div>

    <div class="mb-3">
        <label for="db_pass" class="form-label">Mot de passe</label>
        <input type="password" class="form-control" name="db_pass" id="db_pass" value="">
        <small class="text-muted">Laissez vide si l'utilisateur n'a pas de mot de passe.</small>
    </div>

    <button type="submit" class="btn btn-primary">Valider</button>
    <?= setup_csrf_field() ?>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>