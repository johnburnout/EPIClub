<?php
	
	// Inclusion des fichiers de configuration
	require __DIR__.'/config.php';
	require __DIR__."/includes/debug.php";
	require __DIR__."/includes/session.php";
	
	// Vérification des permissions
	// Validation CSRF
	if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
		throw new Exception('Erreur de sécurité: Token CSRF invalide');
	}
	
	if (!$isAdmin) {
		header('Location: index.php?');
		exit();
	}
	
	// #############################
	// Initialisation variables
	// #############################
	$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
	$id_0 = $id;
	//$bouton = '';
	$retour = isset($_GET['retour']) ? $_GET['retour'] : (isset($_POST['retour']) ? $_POST['retour'] : 'liste_fabricants.php');
	$avis = '';
	
	// #############################
	// Opérations base de données
	// #############################
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	
	try {
		$connection = new mysqli($host, $username, $password, $dbname);
		$connection->set_charset("utf8mb4");
		
		// Si ID non fourni, on récupère le max
		if ($id === 0) {
			$stmt = $connection->prepare("SELECT MAX(id) AS max_id FROM fabricant");
			$stmt->execute();
			$result = $stmt->get_result();
			$idmax = $result->fetch_assoc();
			$id = (int)$idmax['max_id'];
		}
		
		// Suppression
		$stmt1 = $connection->prepare("DELETE FROM fabricant WHERE id = ?");
		$stmt1->bind_param("i", $id);
		$stmt1->execute();
		
		if ($connection->affected_rows > 0) {
			$avis = "Le fabricant a été supprimé avec succès";
			$succes = true;
			
			// Réinitialisation auto_increment si table vide
			if (isset($_GET['edit'])) {
				$connection->query("ALTER TABLE fabricant AUTO_INCREMENT = 1");
			}
		} else {
			$avis = "Aucun fabricant trouvé à supprimer";
			$succes = false;
		}
		
		
		$connection->close();
	} catch (mysqli_sql_exception $e) {
		error_log("[" . date('Y-m-d H:i:s') . "] Erreur DB: " . $e->getMessage() . " dans " . __FILE__);
		$avis = "Une erreur technique est survenue lors de la suppression du fabricant";
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
			<div class="alert <?= $succes ? 'alert-success' : 'alert-error' ?>">
				<?= htmlspecialchars($avis) ?>
			</div>
			<?php endif; ?>
			<div>
				<p>
					<form action=<?=$retour?> >
						<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
						<input type="submit" class="btn btn-primary" name="retour" value="Retour">
					</form>
				</p>
			</div>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>