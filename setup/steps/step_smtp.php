<?php

use Epiclub\Engine\EnvironmentFileParser;

/**
 * Smtp
 */

$smtp = [
    'domain' => 'smtp.example.com',
    'port' => 25,
    'user' => 'root',
    'password' => 'secret'
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /** @todo Need form validation here */

        if (empty($errors)) {

            # $mailer_dsn = 'smtp://user:password@smtp.example.com:25',
            $mailer_dsn = "smtp://" . $_POST['user'] . ":" . $_POST['password'] . "@" . $_POST['domain'] . ":" . $_POST['port'];

            $env = new EnvironmentFileParser();
            $env->set('mailer_dsn', $mailer_dsn);
        }

        if (empty($errors)) {
            header("Location: ?step=admin");
            exit();
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
            <h2>Mail via Smtp</h2>
            <div class="mb-3">
                <label for="domain">Domain</label>
                <input type="text" class="form-control" name="domain" id="domain" value="<?= $smtp['domain']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="port">Port</label>
                <input type="mumber" class="form-control" name="port" id="port" value="<?= $smtp['port']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="user">Nom d'utilisateur</label>
                <input type="text" class="form-control" name="user" id="user" value="<?= $smtp['user']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="password">Mot de passe</label>
                <input type="password" class="form-control" name="db_pass" id="password" value="<?= $smtp['password']; ?>">
            </div>
            <div class="mb-3">
                <button type="submit" class="btn btn-primary">Valider</button>
            </div>
        </form>
    </div>
</body>

</html>