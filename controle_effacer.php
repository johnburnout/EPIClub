<?php

	// Inclusion des fichiers de configuration
	require __DIR__ . '/config.php';
	require __DIR__.'/includes/communs.php';
	
	// Vérification des permissions
	// Validation CSRF
	if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
		throw new Exception('Erreur de sécurité: Token CSRF invalide');
	}

	if (!$isLoggedIn) {
		header('Location: index.php?');
		exit();
	}
	
	// #############################
	// Initialisation variables
	// #############################
	$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
	//$bouton = '';
	$avis = '';
	$retour = 'index.php';
	
	// Vérification des permissions
	if ($_SERVER['REQUEST_METHOD'] !== 'GET' or !$isLoggedIn or ($id != $_SESSION['controle_en_cours'])) {
		die('Erreur : Permissions insuffisantes');
	}
	
	// #############################
	// Opérations base de données
	// #############################
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	
	try {
		$connection = new mysqli($host, $username, $password, $dbname);
		$connection->set_charset("utf8mb4");
		
		// Si ID non fourni, on récupère le max
		if ($id === 0) {
			$stmt = $connection->prepare("SELECT MAX(id) AS max_id FROM verification");
			$stmt->execute();
			$result = $stmt->get_result();
			$idmax = $result->fetch_assoc();
			$id = (int)$idmax['max_id'];
		}
		
		// Suppression
		$stmt1 = $connection->prepare("DELETE FROM verification WHERE id = ?");
		$stmt1->bind_param("i", $id);
		$stmt1->execute();
		
		if ($connection->affected_rows > 0) {
			$avis = "Le contrôle a été supprimé avec succès";
			$_SESSION['controle_en_cours'] = 0;
			$_SESSION['epi_controles'] = '';
			$sql = "UPDATE utilisateur SET
			controle_en_cours = 0
			WHERE username = ?";
			
			$stmt2 = $connection->prepare($sql);
			if (!$stmt2) {
				throw new Exception("Erreur de préparation de la requête: " . $connection->error);
			}
			
			// 6. EXECUTION DE LA REQUETE
			$stmt2->bind_param(
				"s",
				$utilisateur
			);
			
			$stmt2->execute();
			
			// Réinitialisation auto_increment si table vide
			if ($id === 0) {
				$connection->query("ALTER TABLE verification AUTO_INCREMENT = 1");
			}
		} else {
			$avis = "Aucun enregistrement trouvé à supprimer";
		}
		
		
		$connection->close();
	} catch (mysqli_sql_exception $e) {
		error_log("[" . date('Y-m-d H:i:s') . "] Erreur DB: " . $e->getMessage() . " dans " . __FILE__);
		$avis = "Une erreur technique est survenue lors de la suppression";
	}
	
?>

<!DOCTYPE html>
<html lang="fr">
	<head>
		<?php include __DIR__.'/includes/head.php';?>
	</head>
	<body>
		<header style="text-align: right; padding: 10px;">
			<?php include __DIR__.'/includes/bandeau.php';?>
		</header>
		<main>
			<?php include __DIR__.'/includes/en_tete.php';?>
			<?php if ($avis): ?>
			<div class="alert <?= strpos($avis, 'Attention') !== false ? 'alert-warning' : 'alert-info' ?>">
				<?= htmlspecialchars($avis) ?>
			</div>
			<?php endif; ?>
			<div>
				<p>
					<form action="<?= $retour ?>" >
						<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
						<input type="submit" class="btn return-btn" name="retour" value="Retour">
					</form>
				</p>
			</div>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>