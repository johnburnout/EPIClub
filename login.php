<?php

$error_autentification = null;
$form_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$_POST['csrf_token'] || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new \Exception('Erreur de sécurité: Token CSRF invalide');
    }

    if (!isset($_POST['username'])) {
        $form_errors['username'] = "Le nom d'utilisateur est requis.";
    }

    if (!isset($_POST['password'])) {
        $form_errors['password'] = "Le mot de passe est requis.";
    }

    if (empty($form_errors)) {
        $stmt = $db->prepare("SELECT *
                FROM utilisateur 
                WHERE username = ? 
                AND est_actif = 1
                LIMIT 1
            ");
        $stmt->bind_param("s", $_POST['username']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Vérification du mot de passe
            if (password_verify($_POST['password'], $user['password'])) {
                $_SESSION['user'] = $user;

                // Mise à jour last_login
                $update_stmt = $db->prepare("UPDATE utilisateur SET last_login = NOW() WHERE id = ?");
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();

                session_regenerate_id();
                header('Location: .');
                exit();
            }
        }

        $error_autentification = "Identifiants incorrects";
    }
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <?php include __DIR__ . '/includes/head.php'; ?>
</head>

<body>
    <header style="text-align: right; padding: 10px;">
        <?php include __DIR__ . '/includes/bandeau.php'; ?>
    </header>
    <main>
        <?php include __DIR__ . '/includes/en_tete.php'; ?>
        <div class="login-container">
            <h3 style="text-align: left;">Identification</h3>

            <?php if (null !== $error_autentification): ?>
                <div class="error"><?= $error_autentification; ?></div>
            <?php endif; ?>

            <form method="post">
                <div>
                    <label for="username">Identifiant :</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                <div>
                    <label for="password">Mot de passe :</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div style="text-align: left; margin-top: 20px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
                    <button type="submit" class="btn btn-primary" name="connexion">Me connecter</button>
                    <a href="/oubli_mdp.php">Mot de passe oublié ?</a>
                </div>
            </form>
        </div>
    </main>
    <footer>
        <?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
    </footer>
</body>

</html>