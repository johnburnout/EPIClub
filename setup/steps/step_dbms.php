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
 */

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
    $db_host = trim($_POST['db_host'] ?? '');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';

    // ---- Validation stricte ----
    if (empty($db_host)) {
        $errors[] = "L'adresse du serveur est requise.";
    } elseif (!preg_match('/^[a-zA-Z0-9\.\:\-\_]+$/', $db_host)) {
        $errors[] = "L'adresse du serveur contient des caractères invalides.";
    }

    if (empty($db_name)) {
        $errors[] = "Le nom de la base est requis.";
    } elseif (!preg_match('/^[a-zA-Z0-9\_]+$/', $db_name)) {
        $errors[] = "Le nom de la base ne peut contenir que des lettres, chiffres et underscores.";
    } elseif (strlen($db_name) > 64) {
        $errors[] = "Le nom de la base est trop long (64 caractères max).";
    }

    if (empty($db_user)) {
        $errors[] = "Le nom d'utilisateur est requis.";
    } elseif (!preg_match('/^[a-zA-Z0-9\.\-\_\@]+$/', $db_user)) {
        $errors[] = "Le nom d'utilisateur contient des caractères invalides.";
    }

    // ---- Connexion et installation ----
    if (empty($errors)) {
        try {
            $dsn = 'mysql:host=' . $db_host . ';charset=utf8mb4';
            $pdo = new \PDO($dsn, $db_user, $db_pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Créer la base si elle n'existe pas.
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db_name}`");

            // ---- Vérifier si la base contient déjà des tables ----
            // Si oui → on refuse. La suppression doit être manuelle (CLI).
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
            $existingTables = count($tables);

            if ($existingTables > 0) {
                $errors[] = sprintf(
                    "La base « %s » contient déjà %d table(s). " .
                    "L'installation ne peut pas continuer. " .
                    "Pour réinstaller, supprimez la base manuellement " .
                    "et relancez l'installateur.",
                    htmlspecialchars($db_name),
                    $existingTables
                );

                error_log(sprintf(
                    '[setup/dbms] Installation refusée : la base %s contient déjà %d table(s).',
                    $db_name,
                    $existingTables
                ));
            } else {
                // ---- Exécution des migrations ----
                $migrationsDir = __DIR__ . '/../../migrations';

                if (!is_dir($migrationsDir)) {
                    throw new \RuntimeException(
                        "Le dossier des migrations est introuvable : $migrationsDir"
                    );
                }

                $migrationManager = new \Epiclub\Engine\MigrationManager(
                    $pdo,
                    $migrationsDir
                );

                $applied = $migrationManager->migrate(function ($version) {
                    error_log('[setup/dbms] Migration appliquée : ' . $version);
                });

                if (empty($applied)) {
                    throw new \RuntimeException(
                        "Aucune migration n'a été appliquée. " .
                        "Vérifiez le contenu du dossier migrations/."
                    );
                }

                error_log(sprintf(
                    '[setup/dbms] Installation OK : %d migration(s) appliquée(s).',
                    count($applied)
                ));

                // ---- Écriture de la config ----
                $env = new \Epiclub\Engine\EnvironmentFileParser();
                $env->set('DB_HOST', $db_host);
                $env->set('DB_NAME', $db_name);
                $env->set('DB_USER', $db_user);
                $env->set('DB_PASS', $db_pass);

                header('Location: ?step=admin');
                exit();
            }
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
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>