<?php

use Epiclub\Domain\ClubManager;

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
        header('Location: ?step=final');
        exit();
    }
}

?>

<?php require __DIR__ . '/../includes/header.php'; ?>

<h1>Club</h1>
<hr>

<form method="post">
    <div class="mb-3">
        <label for="nom" class="form-label">Nom</label>
        <input type="text" class="form-control" name="nom" id="nom" value="<?= htmlspecialchars($club['nom']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="activite" class="form-label">Activité</label>
        <select class="form-select" name="activite" id="activite">
            <?php foreach ($activites as $activite) { ?>
                <option value="<?= $activite; ?>"><?= $activite; ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">Description</label>
        <input type="text" class="form-control" name="description" id="description" value="<?= htmlspecialchars($club['description']); ?>">
    </div>
    <div class="mb-3">
        <label for="email" class="form-label">Adresse mail</label>
        <input type="email" class="form-control" name="email" id="email" value="<?= htmlspecialchars($club['email']); ?>">
    </div>
    <div class="mb-3">
        <label for="phone" class="form-label">N° téléphone</label>
        <input type="text" class="form-control" name="phone" id="phone" value="<?= htmlspecialchars($club['phone']); ?>">
    </div>
    <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" name="install_default_activity_data" id="install_default_activity_data">
        <label class="form-check-label" for="install_default_activity_data">Installer les données par défaut* ?</label>
    </div>
    <button type="submit" class="btn btn-primary">Valider</button>
    <div class="mt-3">
        <p class="text-muted">* Initialise la database avec des données (catégories, fournisseurs) pour l'activité sélectionnée.</p>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>