<?php

use Epiclub\Domain\ClubManager;

/**
 * Club
 */

$activites = [
    'Alpinisme',
    'Escalade'
];

$club = [
    'nom' => '',
    'activite' => '',
    'description' => '',
    'email' => '',
    'phone' => ''
];

$install_default_activity_data = false;

$form_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /** @todo Need form validation here */

    if (empty($form_errors)) {
        // Save club params
        $club = [
            'nom' => $_POST['nom'],
            'activite' => $_POST['activite'],
            'description' => $_POST['description'],
            'email' => $_POST['email'],
            'phone' => $_POST['phone'],
        ];
        $clubManager = new ClubManager();
        $clubManager->save($club);

        if (isset($_POST['install_default_activity_data'])) {
            /** @todo  install defaults */
        }
        header("Location: ?step=final");
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
            <h2>Club</h2>
            <div class="mb-3">
                <label for="nom">Nom</label>
                <input type="text" class="form-control" name="nom" id="nom" value="<?= $club['nom']; ?>" required>
            </div>
            <div class="mb-3">
                <label for="activite">Activité</label>
                <select class="form-select" name="activite" id="activite">
                    <?php foreach ($activites as $activite) { ?>
                        <option value="<?= $activite; ?>"><?= $activite; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="description">Description</label>
                <input type="text" class="form-control" name="description" id="description" value="<?= $club['description']; ?>">
            </div>
            <div class="mb-3">
                <label for="email">Adresse mail</label>
                <input type="email" class="form-control" name="email" id="email" value="<?= $club['email']; ?>">
            </div>
            <div class="mb-3">
                <label for="phone">N° téléphone</label>
                <input type="text" class="form-control" name="phone" id="phone" value="<?= $club['phone']; ?>">
            </div>
            <div class="mb-3">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="install_default_activity_data" id="install_default_activity_data">
                    <label class="form-check-label" for="install_default_activity_data">Installer les données par défaut* ?</label>
                </div>
            </div>
            <div class="mb-3">
                <button type="submit" class="btn btn-primary">Valider</button>
            </div>
            <div class="mb-3">
                <p>* Initialise la database avec des données (categories, fournisseurs) pour l'activité sélectionnée.</p>
            </div>
        </form>
    </div>
</body>

</html>