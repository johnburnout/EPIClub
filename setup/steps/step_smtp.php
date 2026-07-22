<?php

use Epiclub\Engine\EnvironmentFileParser;

$smtp = [
    'domain' => 'smtp.example.com',
    'port' => 25,
    'user' => 'root',
    'password' => 'secret'
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /** @todo Need form validation here */

    if (empty($errors)) {
        $mailer_dsn = "smtp://" . $_POST['user'] . ":" . $_POST['password'] . "@" . $_POST['domain'] . ":" . $_POST['port'];
        $env = new EnvironmentFileParser();
        $env->set('mailer_dsn', $mailer_dsn);
    }

    if (empty($errors)) {
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
        <label for="domain" class="form-label">Domaine</label>
        <input type="text" class="form-control" name="domain" id="domain" value="<?= htmlspecialchars($smtp['domain']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="port" class="form-label">Port</label>
        <input type="number" class="form-control" name="port" id="port" value="<?= htmlspecialchars($smtp['port']); ?>" required>
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