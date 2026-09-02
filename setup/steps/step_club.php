<?php

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\UtilisateurManager;

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
    // Validation
    if (empty($_POST['nom'])) { $form_errors[] = 'Le nom du club est requis.'; }
    if (empty($_POST['email']) || !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $form_errors[] = 'L\'adresse email du club est requise et doit être valide.';
    }

    if (empty($form_errors)) {
        $club = [
            'nom' => $_POST['nom'],
            'activite' => $_POST['activite'],
            'description' => $_POST['description'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
        ];

        $clubManager = new ClubManager();

        // 🔍 Vérifier si un club existe déjà
        $existingClub = $clubManager->findParameters(); // méthode qui retourne le club (si un seul)
        if ($existingClub && isset($existingClub['id'])) {
            // Mise à jour
            $club['id'] = $existingClub['id'];
        }

        $clubManager->save($club);

        // 🔧 CRÉER LE SUPER ADMINISTRATEUR
        try {
            $superAdmin = [
                'nom' => 'Admin',
                'prenom' => 'Super',
                'username' => 'admin',
                'email' => $club['email'],
                'password' => password_hash('admin', PASSWORD_DEFAULT),
                'role' => 'ROLE_ADMIN',
                'date_creation' => (new DateTime())->format('Y-m-d H:i:s'),
                'derniere_connexion' => null,
                'last_activity' => null,
                'reset_token' => null,
                'reset_token_expires' => null,
                'reset_email_sent_at' => null,
                'controle_en_cours_id' => null
            ];

            // Vérifier si l'utilisateur 'admin' existe déjà pour éviter un doublon
            $utilisateurManager = new UtilisateurManager();
            $existingUser = $utilisateurManager->findOneByCriteria(['username' => 'admin']);
            if ($existingUser) {
                // Mettre à jour l'utilisateur existant
                $superAdmin['id'] = $existingUser['id'];
            }
            $utilisateurManager->save($superAdmin);
            
            $_SESSION['super_admin_created'] = true;
            $_SESSION['super_admin_email'] = $club['email'];
            $_SESSION['super_admin_password'] = 'admin';
        } catch (\Exception $e) {
            $form_errors[] = 'Erreur lors de la création du super administrateur : ' . $e->getMessage();
        }

        if (empty($form_errors)) {
            if (isset($_POST['install_default_activity_data'])) {
                /** @todo installer les données par défaut */
            }
            header('Location: ?step=final');
            exit();
        }
    }
}

?>

<?php require __DIR__ . '/../includes/header.php'; ?>

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
        <small class="text-muted">Cette adresse sera utilisée pour le compte super administrateur.</small>
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
        <p class="text-muted">🔑 Un compte super administrateur sera créé automatiquement :<br>
        <strong>Identifiant :</strong> admin<br>
        <strong>Mot de passe :</strong> admin</p>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>