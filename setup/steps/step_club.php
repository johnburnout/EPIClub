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

$form_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['nom'])) {
        $form_errors[] = 'Le nom du club est requis.';
    }
    if (empty($_POST['email']) || !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $form_errors[] = 'L\'adresse email du club est requise et doit être valide.';
    }

    if (empty($form_errors)) {
        $club = [
            'nom' => $_POST['nom'],
            'activite' => $_POST['activite'] ?? '',
            'description' => $_POST['description'] ?? '',
            'email' => $_POST['email'],
            'phone' => $_POST['phone'] ?? '',
        ];

        $clubManager = new ClubManager();
        $existingClub = $clubManager->findParameters();
        if ($existingClub && isset($existingClub['id'])) {
            $club['id'] = $existingClub['id'];
        }
        $clubManager->save($club);

        if (isset($_POST['install_default_activity_data'])) {
            /** @todo installer les données par défaut */
        }

        header('Location: ?step=final');
        exit();
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Club</h1>
<hr>

<?php if (!empty($form_errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($form_errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post">
    <div class="mb-3">
        <label for="nom" class="form-label">Nom du club *</label>
        <input type="text" class="form-control" name="nom" id="nom" value="<?= htmlspecialchars($club['nom']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="activite" class="form-label">Activité principale</label>
        <select class="form-select" name="activite" id="activite">
            <?php foreach ($activites as $activite) { ?>
                <option value="<?= $activite; ?>" <?= ($club['activite'] === $activite) ? 'selected' : '' ?>><?= $activite; ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">Description</label>
        <textarea class="form-control" name="description" id="description" rows="3"><?= htmlspecialchars($club['description']); ?></textarea>
    </div>
    <div class="mb-3">
        <label for="email" class="form-label">Adresse mail du club *</label>
        <input type="email" class="form-control" name="email" id="email" value="<?= htmlspecialchars($club['email']); ?>" required>
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