<?php

use Epiclub\Engine\EnvironmentFileParser;

/**
 * Database
 */

$db_params = [
    'db_host' => 'localhost:3306',
    'db_user' => 'root',
    'db_pass' => 'secret',
    'db_name' => 'epiclub'
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /** @todo Need form validation here */

    if (empty($errors)) {
        /** @todo General config */
        /* $general['locale'] = $_POST['locale'];
        $general['timezone'] = $_POST['timezone'];
        ConfigFileParser::save('general', $general); */

        $db_host = $_POST['db_host'];
        $db_name = $_POST['db_name'];
        $db_user = $_POST['db_user'];
        $db_pass = $_POST['db_pass'];

        // check database connection
        try {
            $db = new \PDO('mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8', $db_user, $db_pass);
        } catch (\Throwable $th) {
            $errors['database'] = 'Les paramètres de la connection à la database sont incorrects ou le serveur est inaccessible.';
        }

        /** @todo check database_version */
        /*  foreach ($requirements['dbms'] as $name => $version) {
            // si le serveur n'est pas dans la liste ou si la version est inferieur => erreur
            $form_errors['dbms'] = 'Le serveur de base de données n\'est pas compatible avec la version de l\'application.';
        } */

        if (empty($errors)) {
            $env = new EnvironmentFileParser();
            $env->set('db_host', $db_host);
            $env->set('db_name', $db_name);
            $env->set('db_user', $db_user);
            $env->set('db_pass', $db_pass);

            // Database tables creation
            $sql = file_get_contents(__DIR__ . '/../epiclub.sql');
            if (!empty($sql)) {
                if ($db->exec($sql) === false) {
                    $errors['tables'] = 'Création des tables impossible.';
                }
            }
        }

        if (empty($errors)) {
            header("Location: ?step=smtp");
            exit();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Epiclub</title>
    <link rel="shortcut icon" href="/assets/img/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="/assets/css/cosmo.min.css">
</head>

<body>
    <div class="container">
        <h1>Epiclub | Nouvelle installation</h1>
        <hr>
        <?php if (!empty($errors)) {
            foreach ($errors as $error) { ?>
                <div class="text-danger">
                    <?= $error; ?>
                </div>
        <?php }
        } ?>
        <form method="post">
            <h2>Database</h2>
            <div class="mb-3">
                <label for="db_host">Adresse serveur</label>
                <input type="text" class="form-control" name="db_host" id="db_host" value="<?= $db_params['db_host']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="db_name">Nom de la database</label>
                <input type="text" class="form-control" name="db_name" id="db_name" value="<?= $db_params['db_name']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="db_user">Nom d'utilisateur</label>
                <input type="text" class="form-control" name="db_user" id="db_user" value="<?= $db_params['db_user']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="db_pass">Mot de passe</label>
                <input type="password" class="form-control" name="db_pass" id="db_pass" value="<?= $db_params['db_pass']; ?>">
            </div>
            <div class="mb-3">
                <button type="submit" class="btn btn-primary">Valider</button>
            </div>
        </form>
    </div>
</body>

</html>