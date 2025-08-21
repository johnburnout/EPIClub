<?php
	
	// Inclusion des fichiers de configuration
	require __DIR__ . '/config.php';
	require __DIR__.'/includes/communs.php';
	
	// #############################
	// Initialisation variables
	// #############################
	$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
	$id_0 = $id;
	//$bouton = '';
	$retour = isset($_GET['retour']) ? $_GET['retour'] : (isset($_POST['retour']) ? $_POST['retour'] : 'index.php');
	$avis = '';
	
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
	// Opérations base de données
	// #############################
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	
	try {
		$connection = new mysqli($host, $username, $password, $dbname);
		$connection->set_charset("utf8mb4");
		
		// Si ID non fourni, on récupère le max
		if ($id === 0) {
			$stmt = $connection->prepare("SELECT MAX(id) AS max_id FROM facture");
			$stmt->execute();
			$result = $stmt->get_result();
			$idmax = $result->fetch_assoc();
			$id = (int)$idmax['max_id'];
		}
		
		// Suppression
		$stmt1 = $connection->prepare("DELETE FROM facture WHERE id = ?");
		$stmt1->bind_param("i", $id);
		$stmt1->execute();
		
		// Suppression des EPI liés
		$stmt1 = $connection->prepare("DELETE FROM matos WHERE facture_id = ?");
		$stmt1->bind_param("i", $id);
		$stmt1->execute();
		
		if ($connection->affected_rows > 0) {
			$avis = "La facture a été supprimée avec succès";
			$_SESSION['facture_en_saisie'] = 0;
			$sql = "UPDATE utilisateur SET
			facture_en_saisie = 0
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
			if ($id_0 === 0) {
				$connection->query("ALTER TABLE facture AUTO_INCREMENT = 1");
			}
		} else {
			$avis = "Aucun enregistrement trouvé à supprimer";
		}
		
		
		$connection->close();
	} catch (mysqli_sql_exception $e) {
		error_log("[" . date('Y-m-d H:i:s') . "] Erreur DB: " . $e->getMessage() . " dans " . __FILE__);
		$avis = "Une erreur technique est survenue lors de la suppression";
	}
	
	// Configuration des messages et boutons
	//if ($id > 0 && isset($_POST['supprimer'])) {
	//  $bouton = "<a href='liste_selection.php' class='btn btn-secondary'>Abandonner</a>";
	//  $avis = $avis ?: "Attention ! Voulez-vous vraiment effacer le contrôle #$id ?";
	//}
	//
	//if (isset($_POST['confirmer'])) {
	//  $avis = "Opération confirmée. " . $avis;
	//}
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
					<form action=<?=$retour?> >
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