<?php

use Epiclub\Engine\EnvironmentFileParser;

$smtp = [
    'domain' => '',
    'port' => 587,
    'user' => '',
    'password' => ''
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $smtpEnabled = !empty($_POST['domain']);

    if ($smtpEnabled) {
        if (empty($_POST['port']) || !ctype_digit((string)$_POST['port'])) {
            $errors[] = 'Le port SMTP doit être un nombre.';
        }
        if (empty($_POST['user'])) {
            $errors[] = 'Le nom d\'utilisateur SMTP est requis.';
        }
    }

    if (empty($errors)) {
        $env = new EnvironmentFileParser();

        if ($smtpEnabled) {
            $mailer_dsn = "smtp://" . urlencode($_POST['user']) . ":" . urlencode($_POST['password'] ?? '') . "@" . $_POST['domain'] . ":" . $_POST['port'];
            $env->set('MAILER_DSN', $mailer_dsn);
            $env->set('MAILER_FROM', 'admin@' . $_POST['domain']);
            $env->set('MAILER_NAME', 'EPIClub');
            $env->set('MAILER_ENABLED', 'true');
        } else {
            $env->set('MAILER_DSN', '');
            $env->set('MAILER_ENABLED', 'false');
        }

        header('Location: ?step=club');
        exit();
    }

    $smtp = [
        'domain' => $_POST['domain'] ?? '',
        'port' => $_POST['port'] ?? 587,
        'user' => $_POST['user'] ?? '',
        'password' => $_POST['password'] ?? ''
    ];
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Configuration SMTP</h1>
<hr>

<p class="text-muted">
    Cette étape est <strong>facultative</strong>. Si vous laissez le domaine vide,
    les envois de mail seront désactivés. Vous pourrez configurer SMTP plus tard
    dans l'administration.
</p>

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
        <input type="text" class="form-control" name="domain" id="domain" value="<?= htmlspecialchars($smtp['domain']); ?>">
        <small class="text-muted">Ex: smtp.free.fr, smtp.gmail.com. Laissez vide pour désactiver.</small>
    </div>
    <div class="mb-3">
        <label for="port" class="form-label">Port</label>
        <input type="number" class="form-control" name="port" id="port" value="<?= htmlspecialchars((string)$smtp['port']); ?>">
    </div>
    <div class="mb-3">
        <label for="user" class="form-label">Nom d'utilisateur</label>
        <input type="text" class="form-control" name="user" id="user" value="<?= htmlspecialchars($smtp['user']); ?>">
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Mot de passe</label>
        <input type="password" class="form-control" name="password" id="password" value="<?= htmlspecialchars($smtp['password']); ?>">
    </div>
    <button type="submit" class="btn btn-primary">Valider</button>
    <a href="?step=club" class="btn btn-link">Passer cette étape</a>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>