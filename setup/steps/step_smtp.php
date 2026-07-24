<?php

use Epiclub\Engine\EnvironmentFileParser;

$smtp = [
    'domain' => 'smtp.free.fr',
    'port' => 25,
    'user' => '',
    'password' => ''
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation
    if (empty($_POST['domain'])) { $errors[] = 'Le domaine SMTP est requis.'; }
    if (empty($_POST['port'])) { $errors[] = 'Le port SMTP est requis.'; }
    if (empty($_POST['user'])) { $errors[] = 'Le nom d\'utilisateur SMTP est requis.'; }

    if (empty($errors)) {
        $mailer_dsn = "smtp://" . $_POST['user'] . ":" . $_POST['password'] . "@" . $_POST['domain'] . ":" . $_POST['port'];
        $env = new EnvironmentFileParser();
        $env->set('MAILER_DSN', $mailer_dsn);
        $env->set('MAILER_FROM', 'admin@' . $_POST['domain']);
        $env->set('MAILER_NAME', 'EPIClub');
        
        header('Location: ?step=club');
        exit();
    }
}

?>

<?php require __DIR__ . '/../includes/header.php'; ?>

<h1>Configuration SMTP</h1>
<hr>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post">
    <div class="mb-3">
        <label for="domain" class="form-label">Domaine SMTP</label>
        <input type="text" class="form-control" name="domain" id="domain" value="<?= htmlspecialchars($smtp['domain']); ?>" required>
        <small class="text-muted">Ex: smtp.free.fr, smtp.gmail.com, etc.</small>
    </div>
    <div class="mb-3">
        <label for="port" class="form-label">Port</label>
        <input type="number" class="form-control" name="port" id="port" value="<?= htmlspecialchars($smtp['port']); ?>" required>
        <small class="text-muted">Généralement 25, 465 (SSL) ou 587 (TLS).</small>
    </div>
    <div class="mb-3">
        <label for="user" class="form-label">Nom d'utilisateur</label>
        <input type="text" class="form-control" name="user" id="user" value="<?= htmlspecialchars($smtp['user']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Mot de passe</label>
        <input type="password" class="form-control" name="password" id="password" value="<?= htmlspecialchars($smtp['password']); ?>">
    </div>
    <button type="submit" class="btn btn-primary">Valider</button>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>