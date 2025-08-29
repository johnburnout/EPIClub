<?php

require __DIR__ . '/app/bootstrap.php';

$password_reset_expiry = 1800; // 30 minutes en secondes
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	if (!$_POST['csrf_token'] || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
		throw new \Exception('Erreur de sécurité: Token CSRF invalide');
	}

	try {
		$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

		if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception("Veuillez entrer une adresse email valide");
		}

		try {
			$checkStmt = $db->prepare("SELECT id FROM utilisateur WHERE email = ?");
			$checkStmt->bind_param("s", $email);
			$checkStmt->execute();

			$result = $checkStmt->get_result();
			$donnees = $result->fetch_assoc();
			$userExists = ($result->num_rows > 0);

			if (!$userExists) {
				throw new \Exception("Aucun compte trouvé avec cette adresse email");
			}
		} catch (\Exception $e) {
			error_log("Erreur DB: " . $e->getMessage());
			throw new \Exception("Erreur lors de la vérification de l'email");
		}

		$token = bin2hex(random_bytes(32));
		$expires = time() + $password_reset_expiry;

		$_SESSION['password_reset'] = [
			'email' => $email,
			'token' => $token,
			'expires' => $expires,
			'id' => $donnees['id']
		];

		$reset_link = $site_url . "/reset_password.php?token=$token&email=" . urlencode($email) . "&id=" . $donnees['id'];
		$subject = "Réinitialisation de votre mot de passe - $site_name";
		$message = "
			<html>
			<head>
				<title>Réinitialisation de mot de passe</title>
			</head>
			<body>
				<h2>Réinitialisation de votre mot de passe</h2>
				<p>Bonjour,</p>
				<p>Vous avez demandé à réinitialiser votre mot de passe pour $site_name.</p>
				<p>Cliquez sur le lien suivant pour procéder :</p>
				<p><a href='$reset_link'>$reset_link</a></p>
				<p>Ce lien expirera dans 30 minutes.</p>
				<p>Si vous n'avez pas fait cette demande, veuillez ignorer cet email.</p>
				<br>
				<p>Cordialement,<br>L'équipe $site_name</p>
			</body>
			</html>
			";

		$headers = [
			'From' => $admin_email,
			'Reply-To' => $admin_email,
			'MIME-Version' => '1.0',
			'Content-type' => 'text/html; charset=utf-8',
			'X-Mailer' => 'PHP/' . phpversion()
		];

		$headersString = '';
		foreach ($headers as $key => $value) {
			$headersString .= "$key: $value\r\n";
		}

		if (!mail($email, $subject, $message, $headersString)) {
			throw new \Exception("Une erreur est survenue lors de l'envoi de l'email");
		}

		$success = true;
	} catch (\Exception $e) {
		$error = $e->getMessage();
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
	<main class="container">
		<?php include __DIR__ . '/includes/en_tete.php'; ?>
		<div class="password-reset-container">
			<h1>Mot de passe oublié</h1>

			<?php if ($success): ?>
				<div class="message success">
					<p>Un email de réinitialisation a été envoyé à l'adresse fournie.</p>
					<p>Veuillez vérifier votre boîte de réception (et vos spams).</p>
				</div>
			<?php else: ?>
				<?php if (!empty($error)): ?>
					<div class="message error"><?= htmlspecialchars($error); ?></div>
				<?php endif; ?>

				<form method="post" action="">
					<div class="form-group">
						<label for="email">Adresse email</label>
						<input type="email" id="email" name="email" required
							value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
					</div>

					<button type="submit">Envoyer le lien de réinitialisation</button>
				</form>

				<div class="login-link">
					<a href="login.php">Retour à la connexion</a>
				</div>
			<?php endif; ?>
		</div>
	</main>
	<footer>
		<?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
	</footer>
</body>

</html>