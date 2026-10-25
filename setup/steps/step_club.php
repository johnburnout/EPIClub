<?php

use Epiclub\Process\InstallationService;

$activites = InstallationService::ACTIVITES;

$club = [
    'nom' => '',
    'activite' => '',
    'description' => '',
    'email' => '',
    'phone' => ''
];

$form_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [#107] CSRF : rejette les POST forgés cross-site.
    if (!setup_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $form_errors[] = 'Erreur de sécurité : jeton CSRF invalide ou expiré. Rechargez la page et réessayez.';
    }

    $saisie = [
        'nom'         => (string) ($_POST['nom'] ?? ''),
        'activite'    => (string) ($_POST['activite'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'email'       => (string) ($_POST['email'] ?? ''),
        'phone'       => (string) ($_POST['phone'] ?? ''),
    ];

    // Règles partagées avec bin/installer.php
    $form_errors = array_merge($form_errors, InstallationService::validerClub($saisie));

    if (empty($form_errors)) {
        (new InstallationService(dirname(__DIR__, 2)))->enregistrerClub(
            InstallationService::connexionDepuisConfig(InstallationService::lireConfig(__DIR__ . '/../../.env.local.php')),
            $saisie
        );

        header('Location: ?step=categories');
        exit();
    }

    $club = $saisie;
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
    <button type="submit" class="btn btn-primary">Valider</button>
    <?= setup_csrf_field() ?>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>