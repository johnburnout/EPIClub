<?php

/**
 * Requirements
 */

$requirements = require(__DIR__ . '/../requierements.php');
$extention_loaded = get_loaded_extensions();
$errors = [];

if (!version_compare(PHP_VERSION, $requirements['php_version'], '>=')) {
    $errors['php_version'] = 'La version php n\'est pas compatible avec la version de l\'application.';
}

foreach ($requirements['php_extentions'] as $extention) {
    if (!in_array($extention, $extention_loaded)) {
        $error['extentions'][$extention] = "La librairie $extention n'est pas installée et/ou activée.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($errors)) {
        header("Location: ?step=dbms");
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
        <h2>Vérification de votre environement</h2>
        <form method="post">
            <div class="mb-3">
                Php version:
                <div class="<?= (isset($errors['php_version']) ? 'text-danger' : 'text-success'); ?>">
                    <?= PHP_VERSION; ?> <?= ($errors['php_version'] ?? ''); ?>
                </div>
            </div>
            <div class="mb-3">
                Php extentions:
                <?php foreach ($requirements['php_extentions'] as $extention) { ?>
                    <div class="<?= (isset($errors['extentions'][$extention]) ? 'text-danger' : 'text-success'); ?>">
                        <?= $extention; ?> <?= ($errors['extentions'][$extention] ?? ''); ?>
                    </div>
                <?php } ?>
            </div>
            <div class="mb-3">
                <button type="submit" class="btn btn-primary">Valider</button>
            </div>
        </form>
    </div>
</body>

</html>