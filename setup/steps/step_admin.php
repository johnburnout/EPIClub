<?php

/**
 * Administrateur
 */

use Epiclub\Engine\EtatInstallation;
use Epiclub\Exception\InstallationException;
use Epiclub\Process\InstallationService;

$admin = [
    'nom' => '',
    'prenom' => '',
    'username' => '',
    'email' => '',
];
$form_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [#107] CSRF : rejette les POST forgés cross-site — cette étape
    // crée le compte SUPER_ADMIN, cible n°1 du détournement d'install.
    if (!setup_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $form_errors[] = 'Erreur de sécurité : jeton CSRF invalide ou expiré. Rechargez la page et réessayez.';
    }

    $saisie = [
        'nom'      => (string) ($_POST['nom'] ?? ''),
        'prenom'   => (string) ($_POST['prenom'] ?? ''),
        'username' => (string) ($_POST['username'] ?? ''),
        'email'    => (string) ($_POST['email'] ?? ''),
        'password' => (string) ($_POST['password'] ?? ''),
    ];

    // Validation (règles partagées avec bin/installer.php)
    $form_errors = array_merge(
        $form_errors,
        InstallationService::validerAdmin($saisie, (string) ($_POST['password_confirm'] ?? ''))
    );

    if (empty($form_errors)) {
        $envFilePath = __DIR__ . '/../../.env.local.php';
        $config = InstallationService::lireConfig($envFilePath);
        try {
            // Le tout premier compte de l'installation est SUPER_ADMIN et
            // protégé. [#108 - Dette 26] L'étape n'est pas re-exécutable :
            // si un compte existe déjà, le service refuse (pas de doublon
            // de SUPER_ADMIN, pas de régénération de SECRET_KEY).
            (new InstallationService(dirname(__DIR__, 2)))->creerSuperAdmin(
                InstallationService::connexionDepuisConfig($config),
                $saisie
            );

            // Seule cette session peut terminer l'assistant (SMTP, club,
            // catégories) : pour tout autre visiteur, l'instance est
            // désormais installée (EtatInstallation, app/bootstrap.php).
            $_SESSION[EtatInstallation::SESSION_ADMIN_CREE] = true;

            // Clé de chiffrement : jamais régénérée si elle existe déjà.
            $cle = InstallationService::cleDeChiffrement($config);
            if ($cle !== []) {
                InstallationService::ecrireConfig($envFilePath, $cle);
            }

            header('Location: ?step=smtp');
            exit();
        } catch (InstallationException $e) {
            $form_errors[] = $e->getMessage();
        }
    }

    $admin = array_intersect_key($saisie, $admin);
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Administrateur</h1>
<hr>

<p class="text-muted">
    Créez le compte super administrateur. Ce compte aura tous les droits.
    Choisissez un mot de passe robuste et unique.
</p>

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
        <label for="nom" class="form-label">Nom</label>
        <input type="text" class="form-control" name="nom" id="nom" value="<?= htmlspecialchars($admin['nom']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="prenom" class="form-label">Prénom</label>
        <input type="text" class="form-control" name="prenom" id="prenom" value="<?= htmlspecialchars($admin['prenom']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="username" class="form-label">Nom d'utilisateur</label>
        <input type="text" class="form-control" name="username" id="username" value="<?= htmlspecialchars($admin['username']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="email" class="form-label">Adresse mail</label>
        <input type="email" class="form-control" name="email" id="email" value="<?= htmlspecialchars($admin['email']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Mot de passe</label>
        <input type="password" class="form-control" name="password" id="password" minlength="12" required>
        <small class="text-muted">12 caractères minimum.</small>
    </div>
    <div class="mb-3">
        <label for="password_confirm" class="form-label">Confirmer le mot de passe</label>
        <input type="password" class="form-control" name="password_confirm" id="password_confirm" minlength="12" required>
    </div>
    <button type="submit" class="btn btn-primary">Valider</button>
    <?= setup_csrf_field() ?>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>