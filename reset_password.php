<?php
require __DIR__.'/config.php';
require __DIR__.'/includes/communs.php';

//session_start();

// Initialisation des variables
$error = '';
$success = false;
$show_form = false;
$email = '';
$token = '';
$connection = null;

try {
	// Vérification des paramètres GET
	if (isset($_GET['token']) && isset($_GET['email'])) {
		$token = trim($_GET['token']);
		$email = trim(urldecode($_GET['email']));
		$id = (int)$_GET['id'];
		
		// Validation basique des entrées
		if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($token) !== 64) {
			throw new Exception("Paramètres invalides dans l'URL");
		}
		
		$show_form = true;
	}

	// Traitement du formulaire de réinitialisation
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
		$token = trim($_POST['token'] ?? '');
		$id = (int)$_POST['id'];
		if (!ctype_xdigit($token) || strlen($token) !== 64) {
			throw new Exception("Format de token invalide");
		}
		$mdp = $_POST['password'] ?? '';
		$confirm_password = $_POST['confirm_password'] ?? '';

		// Validation des données
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception("Adresse email invalide");
		}

		if (empty($token) || strlen($token) !== 64) {
			throw new Exception("Token invalide");
		}

		if (strlen($mdp) < 8) {
			throw new Exception("Le mot de passe doit contenir au moins 8 caractères");
		}

		if ($mdp !== $confirm_password) {
			throw new Exception("Les mots de passe ne correspondent pas");
		}

		// Vérification du token en session
		if (!isset($_SESSION['password_reset']) || 
			!hash_equals($_SESSION['password_reset']['email'], $email) || 
			!hash_equals($_SESSION['password_reset']['token'], $token) ||
			$_SESSION['password_reset']['expires'] < time()) {
			throw new Exception("Lien invalide ou expiré");
		}

		// Connexion à la base de données
		$connection = new mysqli($host, $username, $password, $dbname);
		if ($connection->connect_error) {
			throw new Exception("Erreur de connexion à la base de données");
		}
		$connection->set_charset("utf8mb4");

		// Hashage du nouveau mot de passe (décommentez en production)
		$password_hash = password_hash($password, PASSWORD_DEFAULT);
		// Pour les tests, on utilise le mot de passe en clair (à retirer en production)
		//$password_hash = $mdp;

		// Mise à jour du mot de passe
		$stmt = $connection->prepare("UPDATE utilisateur SET password = ? WHERE id = ?");
		if (!$stmt) {
			throw new Exception("Erreur de préparation de la requête");
		}
		
		$stmt->bind_param("si", $password_hash, $id);
		$stmt->execute();

		if ($stmt->affected_rows === 0) {
			throw new Exception("Mot de passe inchangé");
		}

		// Nettoyage de la session
		unset($_SESSION['password_reset']);
		$success = true;
		$show_form = false;
	}
} catch (Exception $e) {
	$error = $e->getMessage();
	$show_form = isset($email) && isset($token);
} finally {
	if ($connection instanceof mysqli) {
		$connection->close();
	}
}
?>

<!DOCTYPE html>
<html lang="fr">
	<head>
		<?php include __DIR__.'/includes/head.php'; ?>
	</head>
	<body>
		<header style="text-align: right; padding: 10px;">
			<?php include __DIR__.'/includes/bandeau.php'; ?>
		</header>
		<main class="container">		
			<?php include __DIR__.'/includes/en_tete.php'; ?>
			<div class="reset-container">
				<h1>Réinitialisation du mot de passe</h1>
				
				<?php if ($success): ?>
					<div class="message success">
						<p>Votre mot de passe a été réinitialisé avec succès.</p>
						<p>Vous pouvez maintenant vous <a href="login.php">connecter</a> avec votre nouveau mot de passe.</p>
					</div>
				<?php elseif ($show_form): ?>
					<?php if (!empty($error)): ?>
						<div class="message error"><?= htmlspecialchars($error, ENT_QUOTES); ?></div>
					<?php endif; ?>
					
					<form method="post" action="">
						<input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES); ?>">
						<input type="hidden" name="id" value="<?= $id ?>">
						<input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES); ?>">
						
						<div class="form-group">
							<label for="password">Nouveau mot de passe</label>
							<input type="password" id="password" name="password" required minlength="8">
							<div class="password-strength">Minimum 8 caractères</div>
						</div>
						
						<div class="form-group">
							<label for="confirm_password">Confirmer le nouveau mot de passe</label>
							<input type="password" id="confirm_password" name="confirm_password" required minlength="8">
						</div>
						
						<button type="submit">Réinitialiser le mot de passe</button>
					</form>
					
					<script>
						document.querySelector('form').addEventListener('submit', function(e) {
							const password = document.getElementById('password').value;
							const confirmPassword = document.getElementById('confirm_password').value;
							
							if (password !== confirmPassword) {
								e.preventDefault();
								alert('Les mots de passe ne correspondent pas');
								return false;
							}
							
							if (password.length < 8) {
								e.preventDefault();
								alert('Le mot de passe doit contenir au moins 8 caractères');
								return false;
							}
							
							return true;
						});
					</script>
				<?php else: ?>
					<div class="message error">
						<p>Lien de réinitialisation invalide ou expiré.</p>
						<p>Veuillez faire une nouvelle <a href="forgot_password.php">demande de réinitialisation</a>.</p>
					</div>
				<?php endif; ?>
			</div>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>