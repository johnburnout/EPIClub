<?php

/**
 * Confirmation de réinstallation.
 *
 * S'affiche uniquement si une installation existe déjà.
 * 1. Demande une confirmation explicite.
 * 2. Demande une authentification admin existante.
 * 3. Pose $_SESSION['setup_reinstall_authorized'] = true si succès.
 */

$envFilePath = __DIR__ . '/../../.env.local.php';

if (!file_exists($envFilePath)) {
    header('Location: ?step=1');
    exit();
}

$config = @include $envFilePath;
if (!is_array($config) || empty($config['DB_HOST']) || empty($config['DB_NAME'])) {
    header('Location: ?step=1');
    exit();
}

$errors = [];
$step1Confirmed = !empty($_POST['confirm_reinstall']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step1Confirmed) {
    $username = trim($_POST['admin_username'] ?? '');
    $password = $_POST['admin_password'] ?? '';

    if ($username === '' || $password === '') {
        $errors[] = 'Veuillez renseigner votre identifiant et votre mot de passe.';
    } else {
        try {
            $dsn = 'mysql:host=' . $config['DB_HOST']
                 . ';dbname=' . $config['DB_NAME']
                 . ';charset=utf8mb4';
            $pdo = new \PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);

            $stmt = $pdo->prepare(
                'SELECT id, username, password FROM utilisateur
                 WHERE username = :u AND role = :r LIMIT 1'
            );
            $stmt->execute([':u' => $username, ':r' => 'ROLE_ADMIN']);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                $errors[] = 'Identifiants incorrects.';
                error_log('[setup/confirm_reinstall] Failed admin login for username: ' . $username);
            } else {
                $_SESSION['setup_reinstall_authorized'] = true;
                $_SESSION['setup_reinstall_authorized_at'] = time();
                $_SESSION['setup_reinstall_authorized_by'] = (int) $user['id'];

                session_regenerate_id(true);

                header('Location: ?step=1');
                exit();
            }
        } catch (\Throwable $e) {
            error_log('[setup/confirm_reinstall] Error: ' . $e->getMessage());
            $errors[] = 'Erreur lors de la vérification. Consultez les logs serveur.';
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>⚠️ Réinstallation détectée</h1>
<hr>

<div class="alert alert-danger">
    <h4>Une installation existe déjà sur ce serveur</h4>
    <p>
        Relancer l'installateur <strong>effacera toutes les données</strong> :
        utilisateurs, équipements, contrôles, journaux, acquisitions, catégories,
        fournisseurs, emplacements…
    </p>
    <p><strong>Cette opération est irréversible.</strong></p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$step1Confirmed): ?>

    <div class="alert alert-warning">
        <p>Souhaitez-vous vraiment réinstaller EPIClub ?</p>
        <form method="post" class="mt-3">
            <input type="hidden" name="confirm_reinstall" value="1">
            <button type="submit" class="btn btn-danger">Oui, je veux réinstaller</button>
            <a href="/" class="btn btn-secondary">Non, revenir au site</a>
        </form>
    </div>

<?php else: ?>

    <div class="alert alert-warning">
        <p>
            Pour confirmer, veuillez vous authentifier avec un compte
            <strong>administrateur existant</strong>.
        </p>
    </div>

    <form method="post" autocomplete="off">
        <input type="hidden" name="confirm_reinstall" value="1">

        <div class="mb-3">
            <label for="admin_username" class="form-label">Nom d'utilisateur admin</label>
            <input type="text" class="form-control" name="admin_username"
                   id="admin_username" required autofocus>
        </div>

        <div class="mb-3">
            <label for="admin_password" class="form-label">Mot de passe</label>
            <input type="password" class="form-control" name="admin_password"
                   id="admin_password" required>
        </div>

        <button type="submit" class="btn btn-danger">Confirmer la réinstallation</button>
        <a href="/" class="btn btn-secondary">Annuler</a>
    </form>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>