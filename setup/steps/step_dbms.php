<?php

// Chargement du parser d'environnement
require_once __DIR__ . '/../includes/EnvironmentFileParser.php';

$db_params = [
    'db_host' => 'localhost:3306',
    'db_user' => 'root',
    'db_pass' => 'secret',
    'db_name' => 'epiclub'
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($errors)) {
        $db_host = $_POST['db_host'];
        $db_name = $_POST['db_name'];
        $db_user = $_POST['db_user'];
        $db_pass = $_POST['db_pass'];

        try {
            $db = new \PDO('mysql:host=' . $db_host . ';charset=utf8', $db_user, $db_pass);
            
            // Créer la base si elle n'existe pas
            $db->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
            $db->exec("USE `$db_name`");
            
            // Désactiver les contraintes de clés étrangères
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            // Exécuter le script SQL
            $sql = file_get_contents(__DIR__ . '/../epiclub.sql');
            if (!empty($sql)) {
                if ($db->exec($sql) === false) {
                    $errors['tables'] = 'Création des tables impossible.';
                }
            }
            
            // Réactiver les contraintes
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            
        } catch (\Throwable $th) {
            $errors['database'] = 'Les paramètres de la connection à la database sont incorrects ou le serveur est inaccessible.';
        }

        if (empty($errors)) {
            // Instancier directement la classe (sans namespace)
            $env = new EnvironmentFileParser(__DIR__ . '/../../.env.local.php');
            $env->set('DB_HOST', $db_host);
            $env->set('DB_NAME', $db_name);
            $env->set('DB_USER', $db_user);
            $env->set('DB_PASS', $db_pass);
            $env->save();

            header("Location: ?step=admin");
            exit();
        }
    }
}

?>

<?php require __DIR__ . '/../includes/header.php'; ?>

<h1>Base de données</h1>
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

<form method="post">
    <div class="mb-3">
        <label for="db_host" class="form-label">Adresse serveur</label>
        <input type="text" class="form-control" name="db_host" id="db_host" value="<?= htmlspecialchars($db_params['db_host']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="db_name" class="form-label">Nom de la base</label>
        <input type="text" class="form-control" name="db_name" id="db_name" value="<?= htmlspecialchars($db_params['db_name']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="db_user" class="form-label">Nom d'utilisateur</label>
        <input type="text" class="form-control" name="db_user" id="db_user" value="<?= htmlspecialchars($db_params['db_user']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="db_pass" class="form-label">Mot de passe</label>
        <input type="password" class="form-control" name="db_pass" id="db_pass" value="<?= htmlspecialchars($db_params['db_pass']); ?>">
    </div>
    <button type="submit" class="btn btn-primary">Valider</button>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>