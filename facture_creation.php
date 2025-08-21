<?php

	// Inclusion des fichiers de configuration
	require __DIR__ . '/config.php';
	require __DIR__.'/includes/communs.php';
	require __DIR__.'/includes/fonctions_bdd_facture.php';
	
	// Vérification des permissions
	// Validation CSRF
	if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
		throw new Exception('Erreur de sécurité: Token CSRF invalide');
	}

	if (!$isAdmin) {
		header('Location: index.php?');
		exit();
	}
	
	if (!isset($_POST) or count($_POST) == 0) {
		$host  = $_SERVER['HTTP_HOST'];
		$uri   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
		$extra = 'index.php';
		header("Location: http://$host$uri/$extra");
		exit;
	}
	
	// #############################
	// Initialisation des variables
	// #############################
	$action =  isset($_POST['action']) ? $_POST['action'] : 'creation';
	$retour = isset($_GET['retour']) ? (int)$_GET['retour'] : (isset($_POST['retour']) ? (int)$_POST['retour'] : '');
	$validation = $action;	
	
	$defaults = [
		'action' => 'creation',
		'id' => 0,
		'utilisateur' => $utilisateur,
		'date_facture' => date('Y-m-d'),
		'vendeur' => "Boutique?",
		'error' => '',
		'success' => '',
		'reference' => 'FACT'.date('Ymd')
	];
	
	// Initialisation des données
	
	foreach ($defaults as $key => $value) {
		$donnees[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?? $value;
		if (in_array($key, ['id'])) {
			$donnees[$key] = (int)$donnees[$key];
		}
	}
	
	// Récupération de l'ID
	$donnees['id'] = isset($_SESSION['facture_en_saisie']) ? intval($_SESSION['facture_en_saisie']) : 0 ;
	$isStarted = ($donnees['id'] != 0);
	
	// #############################
	// Gestion des opérations CRUD
	// #############################
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		// Validation CSRF
		try{
			if ($action === 'creation') {
				$creation = creation_facture([
					'utilisateur' => $utilisateur,
					'date_facture' => $donnees['date_facture'],
					'reference' => $donnees['reference'],
					'vendeur' => $donnees['vendeur']
				]);
				
				if (!$creation['success']) {
					throw new Exception('Erreur lors de la création de la facture: ' . ($creation['error'] ?? ''));
				}
				$donnees['id'] = $creation['id'];
				$_SESSION['facture_en_saisie'] = $donnees['id'];
				$isStarted = true;
				$donnees['success'] = "Nouvelle facture créé avec succès.";
				$action="maj";
			}
			
			$donneesInitiales = $donnees;
			// Lecture des données après création/mise à jour
			if ($donnees['id'] > 0) {
				$_SESSION['facture_en_saisie'] = $donnees['id'];
				$result = lecture_facture($donnees['id'], $utilisateur);
				if (!$result['success']) {
					throw new Exception('Erreur lors de la lecture: ' . ($result['error'] ?? ''));
				}
				$donnees = array_merge($donnees, $result['donnees']);
				$donneesInitiales = $donnees;
				if ($action === 'maj') {
					foreach ($donnees as $key => $value) {
						$donnees[$key] = isset($_POST[$key]) ? $_POST[$key] : $donnees[$key];
					}
					// Traitement des champs du formulaire
					$maj = mise_a_jour_facture([
						'reference' => $donnees['reference'],
						'date_facture' => $donnees['date_facture'],
						'vendeur' => $donnees['vendeur'],
						'utilisateur' => $utilisateur
					], $donnees['id']);
					if (!$maj['success']) {
						throw new Exception('Erreur lors de la mise à jour: ' . ($valid['error'] ?? 'Pas de lignes modifiées'));
					}
				}
			}
		} catch (Exception $e) {
			error_log("[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage());
			$errorMessage = $e->getMessage();
		}
		if (!empty($_FILES['monfichier']['name']) && $donnees['id'] > 0) {
			$allowedExtensions = ['jpeg', 'jpg', 'gif', 'png'];
			$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
			$maxFileSize = 2 * 1024 * 1024; // 2MB
			
			$fileInfo = new finfo(FILEINFO_MIME_TYPE);
			$mimeType = $fileInfo->file($_FILES['monfichier']['tmp_name']);
			
			$fileExtension = strtolower(pathinfo($_FILES['monfichier']['name'], PATHINFO_EXTENSION));
			
			if (!in_array($mimeType, $allowedMimeTypes) || 
				!in_array($fileExtension, $allowedExtensions)) {
					throw new Exception('Type de fichier non autorisé');
				}
			
			if ($_FILES['monfichier']['size'] > $maxFileSize) {
				throw new Exception('Fichier trop volumineux (max 2MB)');
			}
			
			if (!is_uploaded_file($_FILES['monfichier']['tmp_name'])) {
				throw new Exception('Erreur de téléchargement');
			}
			
			$uploadDir = __DIR__.'/factures/';
			if (!is_dir($uploadDir)) {
				if (!mkdir($uploadDir, 0755, true)) {
					throw new Exception('Impossible de créer le dossier de destination');
				}
			}
			
			$newFilename = ($donnees['reference'] ?? 'file') . date('YmdHis') . '.' . $fileExtension;
			$destination = $uploadDir . $newFilename;
			
			if (!move_uploaded_file($_FILES['monfichier']['tmp_name'], $destination)) {
				throw new Exception('Erreur lors du déplacement du fichier');
			}
			
			// Mise à jour de la photo dans la base
			$connection = new mysqli($host, $username, $password, $dbname);
			$connection->set_charset("utf8mb4");
			
			$sql = "UPDATE facture SET fichier = ? WHERE id = ?";
			$stmt = $connection->prepare($sql);
			$stmt->bind_param('si', $newFilename, $donnees['id']);
			$stmt->execute();
			$donnees['fichier'] = $newFilename;
			$connection->close();
		}
		
		// Journalisation
		if (isset($maj['success']) or isset($creation['success'])) {
			$ref = $donnees['reference'];
			$id = $donnees['id'];
			$journalfacture = __DIR__.'/enregistrements/journalfacture_'.$id.'.txt';
			$journal = __DIR__.'/enregistrements/journal'.date('Y').'.txt';
			// Vérification des chemins avant écriture
			$allowedPath = __DIR__.'/enregistrements/';
			if (strpos($journalfacture, $allowedPath) === 0 && strpos($journal, $allowedPath) === 0) {
				$modifications = [];
				
				foreach ($donneesInitiales as $key => $value) {
					if (isset($donnees[$key]) && $donnees[$key] != $value) {
						$modifications[] = "$key modifié: ".$donnees[$key]." -> $value";
					}
				}
				
				$ajoutjournal = '-----'.PHP_EOL."controle $ref ".date('Y/m/d')." $utilisateur".PHP_EOL;
				if (!empty($modifications)) {
					$ajoutjournal .= implode(PHP_EOL, $modifications).PHP_EOL;
				}
				
				try {
					file_put_contents($journalfacture, $ajoutjournal, FILE_APPEND | LOCK_EX);
					file_put_contents($journal, $ajoutjournal, FILE_APPEND | LOCK_EX);
				} catch (Exception $e) {
					error_log("Erreur journalisation: ".$e->getMessage());
				}
			}
		}
	}
	
	$viewData = [
		'date_facture' => sprintf('<input name="date_facture" type="date" required value="%s">', 
			htmlspecialchars(date('Y-m-d',strtotime($donnees['date_facture'])) ?? '', ENT_QUOTES, 'UTF-8')),
		'libelle' => htmlspecialchars($donnees['libelle']),
		'vendeur' => sprintf('<input name="vendeur" type="text" required value="%s">', 
			htmlspecialchars($donnees['vendeur'] ?? '', ENT_QUOTES, 'UTF-8')),
		'reference' => htmlspecialchars($donnees['reference'] ?? '', ENT_QUOTES, 'UTF-8'),
		'action' => htmlspecialchars($action, ENT_QUOTES, 'UTF-8'),
		'id' => (int)$donnees['id'],
		'isEditMode' => $validation === 'maj',
		'isNewMode' => $validation === 'creation',
		'hasFichier' => !empty($donnees['fichier']) && file_exists('factures/' . $donnees['fichier'])
	];
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
			<?php if ($donnees['error']): ?>
			<div class="alert alert-error"><?= $donnees['error'] ?></div>
			<?php endif; ?>
			<?php if ($donnees['success']): ?>
			<div class="alert alert-success"><?= $donnees['success'] ?></div>
		<?php endif; ?>
			
			<form method="post" enctype="multipart/form-data" action="facture_creation.php">
				<input type="hidden" name="retour" value="facture">
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="hidden" name="action" value="<?= $viewData['action'] ?>">
				<input type="hidden" name="id" value="<?= $donnees['id'] ?>">
				
				<table>
					<tbody>
						<tr>
							<td colspan="2">Utilisateur : <?= $utilisateur ?></td>
						</tr>
						<tr>
							<td >Date :</td>
							<td><?= $viewData['date_facture'] ?></td>
						</tr>
						<tr>
							<td width="30%">
								<label for="reference">Référence :</label>
								
							</td>
							<td>
								<input type="text" name="reference" required value="<?= $viewData['reference'] ?>">
							</td>
						</tr>
						<tr>
							<td>
								<label for="vendeur">Vendeur :</label>
							</td>
							<td>
								<?= $viewData['vendeur'] ?>
							</td>
						</tr>
						<tr>
							<td rowspan="<?= $viewData['hasFichier'] ? '1' : '1' ?>">
								<?php if ($viewData['hasFichier']): ?>
								<?php if (!empty($donnees['fichier']) && file_exists('factures/' . $donnees['fichier'])): ?>
								<?php if (strtolower(pathinfo($donnees['fichier'], PATHINFO_EXTENSION)) === 'pdf'): ?>
								<a href="factures/<?= htmlspecialchars($donnees['fichier']) ?>" target="_blank">Voir le PDF</a>
								<?php else: ?>
								<img src="factures/<?= htmlspecialchars($donnees['fichier']) ?>" 
									class="epi-photo" 
									alt="Photo du matériel" 
									width="400">
								<?php endif; ?>
								<?php endif; ?>
								<?php endif; ?>
								<br>
								<input type='file' name='monfichier' accept='image/jpeg,image/png,image/gif, application/pdf'>
							</td>
						</tr>
					</tbody>
				</table>
				
				<div class="form-actions">
					
					<?php if ($isStarted): ?>
					<a href="facture_effacer.php?id=<?= $donnees['id'] ?>&csrf_token=<?=$csrf_token?>"
						onclick="return confirm('Êtes-vous sûr de vouloir supprimer supprimer définitivement cette facture ?')">
						<input type="button"  class="btn btn-danger" value="Annuler la facture" name="supprimer">
					</a>
					<?php else: ?>
					<a href="index.php" class="btn btn-secondary">Retour</a>
					<?php endif; ?>
					
					<button type="submit" name="envoyer" class="btn btn-primary">
						<?= $isStarted ? 'Enregistrer les modifications' : 'Créer la facture' ?>
					</button>`
					<?php if ($isStarted): ?>
					<a href="liste_facture.php?csrf_token=<?=$csrf_token?>"
						onclick="return confirm('Êtes-vous prêt à saisir la facture ?')">
						<input type="button"  class="btn btn-primary" value="Saisir la facture" name="saisir_facture">
					</a>
					<?php endif; ?>
				</div>
			</form>
			<div class="actions">
				<form>
				<a href="index.php" >
					<input type="button" name="retour" value="Retour à l'accueil" class="btn return-btn"/></a>
				</form>
			</div>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>		
	<script>
		// Validation client du formulaire
		document.getElementById('form-controle').addEventListener('submit', function(e) {
			const remarques = this.elements['remarques'].value.trim();
			
			if (remarques.length > 500) {
				alert('Les remarques ne doivent pas dépasser 500 caractères.');
				e.preventDefault();
			}
		});
	</script>
</html>