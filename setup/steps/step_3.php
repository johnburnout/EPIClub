<?php

/**
 * Administrateur
 */

use Epiclub\Domain\UtilisateurManager;

$admin = [
    'nom' => 'Doe',
    'prenom' => 'John',
    'username' => 'JohnDoe',
    'email' => 'johndoe@test.tld',
    'password' => ''
];
$form_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /** @todo Need form validation here */

    if (empty($form_errors)) {
        $admin = [
            'nom' => $_POST['nom'], 
            'prenom' => $_POST['prenom'], 
            'username' => $_POST['username'], 
            'email' => $_POST['email'], 
            'password' => password_hash($_POST['password'], PASSWORD_DEFAULT)
        ];
        $admin['role'] = 'ROLE_ADMIN';
        $admin['date_creation'] = (new DateTime())->format('Y-m-d h:m:s');
        $admin['derniere_connexion'] = null;

        $utilisateurManager = new UtilisateurManager();
        $utilisateurManager->save($admin);

        // save admin params
        header("Location: ?step=4");
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
        <form method="post">
            <h2>Administrateur</h2>
            <div class="mb-3">
                <label for="nom">Nom</label>
                <input type="text" class="form-control" name="nom" id="nom" value="<?= $admin['nom']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="prenom">Prénom</label>
                <input type="text" class="form-control" name="prenom" id="prenom" value="<?= $admin['prenom']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" class="form-control" name="username" id="username" value="<?= $admin['username']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="email">Adresse mail</label>
                <input type="email" class="form-control" name="email" id="email" value="<?= $admin['email']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="password">Mot de passe</label>
                <input type="password" class="form-control" name="password" id="password" value="<?= $admin['password']; ?>" required>
            </div>
            <div class="mb-3">
                <button type="submit" class="btn btn-primary">Valider</button>
            </div>
        </form>
    </div>
</body>

</html>