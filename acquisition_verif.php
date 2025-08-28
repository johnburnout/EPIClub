<?php
	//
	// Inclusion des fichiers de configuration
	require __DIR__.'/config.php';
	require __DIR__."/includes/debug.php";
	require __DIR__."/includes/session.php";
	require __DIR__."/includes/bdd/lecture_acquisition.php";
	require __DIR__."/includes/bdd/maj_acquisition.php";
	
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
	// Initialisation des variables
	// #############################
	
	$acquisition_id = isset($_GET['acquisition_id']) ? (int)$_GET['acquisition_id'] : (isset($_POST['acquisition_id']) ? (int)$_POST['acquisition_id'] : 0);
	
	$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
	
	$retour = isset($_GET['retour']) ? $_GET['retour'] : (isset($_POST['retour']) ? $_POST['retour'] : '');
	
	$defaults = [
		'acquisition_id' => $acquisition_id,
		'utilisateur' => $utilisateur,
		'date_acquisition' => date('Y-m-d'),
		'vendeur' => "Boutique?",
		'error' => '',
		'success' => '',
		'reference' => 'FACT'.date('Ymd')
	];
	
	// Initialisation des données
	
	foreach ($defaults as $key => $value) {
		$donnees[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?? $value;
		if (in_array($key, ['acquisition_id'])) {
			$donnees[$key] = (int)$donnees[$key];
		}
	}
	// l'acquisition est-elle en saisie ?
	$enCours = (($donnees['acquisition_id'] != 0) && ($donnees['acquisition_id'] == intval($_SESSION['acquisition_en_saisie'])));
	
	//adresses journaux
	$journalacquisition = __DIR__.'/utilisateur/enregistrements/journalacquisition_'.$acquisition_id.'.txt';
	$journal = __DIR__.'/utilisateur/enregistrements/journal'.date('Y').'.txt';
	
	// #############################
	// Gestion des opérations CRUD
	// #############################
	if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_GET['edit'] == 'non') {
		// Validation CSRF
		try{
			$donneesInitiales = $donnees;
			// Lecture des données après mise à jour
			if ($donnees['acquisition_id'] > 0) {
				$result = lecture_acquisition($donnees['acquisition_id'], $utilisateur);
				if (!$result['success']) {
					throw new Exception('Erreur lors de la lecture: ' . ($result['error'] ?? ''));
				}
				$donnees = array_merge($donnees, $result['donnees']);
				$donneesInitiales = $donnees;
				foreach ($donnees as $key => $value) {
					$donnees[$key] = isset($_POST[$key]) ? $_POST[$key] : $donnees[$key];
				}
				// Traitement des champs du formulaire
				if ($_GET['edit'] != 'non') {
					$maj = mise_a_jour_acquisition([
						'reference' => $donnees['reference'],
						'date_acquisition' => $donnees['date_acquisition'],
						'vendeur' => $donnees['vendeur'],
						'utilisateur' => $utilisateur
					], $donnees['acquisition_id']);
					if (!$maj['success']) {
						throw new Exception('Erreur lors de la mise à jour: ' . ($valid['error'] ?? 'Pas de lignes modifiées'));
					}
				}
			}
		} catch (Exception $e) {
			error_log("[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage());
			$errorMessage = $e->getMessage();
		}
		if (!empty($_FILES['monfichier']['name']) && $donnees['acquisition_id'] > 0) {
			$allowedExtensions = ['jpeg', 'jpg', 'gif', 'png', 'pdf'];
			//$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
			$allowedMimeTypes = [
				'image/jpeg', 'image/png', 'image/gif', 
				'application/pdf', 
				'application/x-pdf',          // Type MIME alternatif
				'text/pdf'                    // Autre type possible
			];
			$maxFileSize = 2 * 1024 * 1024; // 2MB
			
			$fileInfo = new finfo(FILEINFO_MIME_TYPE);
			$mimeType = $fileInfo->file($_FILES['monfichier']['tmp_name']);
			
			$fileExtension = strtolower(pathinfo($_FILES['monfichier']['name'], PATHINFO_EXTENSION));
			
			// DEBUG: Afficher les valeurs détectées
			error_log("Fichier uploadé: " . $_FILES['monfichier']['name']);
			error_log("Extension détectée: " . $fileExtension);
			error_log("Type MIME détecté: " . $mimeType);
			error_log("Extensions autorisées: " . implode(', ', $allowedExtensions));
			error_log("Types MIME autorisés: " . implode(', ', $allowedMimeTypes));
			
			if (!in_array($mimeType, $allowedMimeTypes) || 
				!in_array($fileExtension, $allowedExtensions)) {
					throw new Exception('Type de fichier non autorisé. Extension: ' . $fileExtension . ', MIME: ' . $mimeType);
				}
			
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
			
			$uploadDir = __DIR__.'/utilisateur/acquisitions/';
			if (!is_dir($uploadDir)) {
				if (!mkdir($uploadDir, 0755, true)) {
					throw new Exception('Impossible de créer le dossier de destination');
				}
			}
			
			$newFilename = ($donnees['reference'] ?? 'file') . '.' . $fileExtension;
			$destination = $uploadDir . $newFilename;
			
			if (!move_uploaded_file($_FILES['monfichier']['tmp_name'], $destination)) {
				throw new Exception('Erreur lors du déplacement du fichier');
			}
			
			// Mise à jour de la photo dans la base
			$connection = new mysqli($host, $username, $password, $dbname);
			$connection->set_charset("utf8mb4");
			
			$sql = "UPDATE acquisition SET fichier = ? WHERE id = ?";
			$stmt = $connection->prepare($sql);
			$stmt->bind_param('si', $newFilename, $donnees['acquisition_id']);
			$stmt->execute();
			$donnees['fichier'] = $newFilename;
			$connection->close();
		}
		
		// Journalisation
		if (isset($maj['success']) or isset($creation['success'])) {
			$ref = $donnees['reference'];
			
			// Vérification des chemins avant écriture
			$allowedPath = __DIR__.'/utilisateur/enregistrements/';
			if (strpos($journalacquisition, $allowedPath) === 0 && strpos($journal, $allowedPath) === 0) {
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
					file_put_contents($journalacquisition, $ajoutjournal, FILE_APPEND | LOCK_EX);
					file_put_contents($journal, $ajoutjournal, FILE_APPEND | LOCK_EX);
				} catch (Exception $e) {
					error_log("Erreur journalisation: ".$e->getMessage());
				}
			}
		}
	}
	$viewData = [
		'date_acquisition' => $enCours ? sprintf('<input name="date_acquisition" type="date" required value="%s">', 
			htmlspecialchars(date('Y-m-d',strtotime($donnees['date_acquisition'])) ?? '', ENT_QUOTES, 'UTF-8')) : date('d/m/Y', strtotime($donnees['date_acquisition'])),
		'libelle' => htmlspecialchars($donnees['libelle']),
		'vendeur' => $enCours ? sprintf('<input name="vendeur" type="text" required value="%s">', 
			htmlspecialchars($donnees['vendeur'] ?? '', ENT_QUOTES, 'UTF-8')) : htmlspecialchars($donnees['vendeur'] ?? '', ENT_QUOTES, 'UTF-8'),
		'reference' => $enCours ? sprintf('<input type="text" name="reference" required value="s">', htmlspecialchars($donnees['reference'] ?? '', ENT_QUOTES, 'UTF-8')) : htmlspecialchars($donnees['reference'] ?? '', ENT_QUOTES, 'UTF-8'),
		'acquisition_id' => (int)$donnees['acquisition_id'],
		'hasFichier' => !empty($donnees['fichier']) && file_exists(__DIR__.'/utilisateur/acquisitions/' . $donnees['fichier'])
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
			
			<form method="post" enctype="multipart/form-data" id='form-controle'>
				<input type="hidden" name="retour" value=$retour >
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="hidden" name="acquisition_id" value="<?= $donnees['acquisition_id'] ?>">
				<input type="hidden" name="id" value="<?= $id ?>">
				
				<table>
					<tbody>
						<tr>
							<td colspan="2">Utilisateur : <?= $utilisateur ?></td>
						</tr>
						<tr>
							<td >Date :</td>
							<td><?= $viewData['date_acquisition'] ?></td>
						</tr>
						<tr>
							<td width="30%">
								<label for="reference">Référence :</label>
								
							</td>
							<td>
								<?= $viewData['reference'] ?>
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
								<?php if (strtolower(pathinfo($donnees['fichier'], PATHINFO_EXTENSION)) === 'pdf'): ?>
								<div class="iframe-container">
										<!-- Remplacez la valeur de src par l'URL de votre iframe -->
										<iframe src="utilisateur/acquisitions/<?= htmlspecialchars($donnees['fichier']) ?>" 
											width="400" height="564" allowfullscreen>
										</iframe>
								</div>
								<a href="utilisateur/acquisitions/<?= htmlspecialchars($donnees['fichier']) ?>" target="_blank" class="btn"><img src="assets/images/pdf.png" alt="Icône PDF" width="25px" height="auto"> Ouvrir le pdf dans une nouvelle fenêtre</a>
								<?php else: ?>
								<img src="utilisateur/acquisitions/<?= htmlspecialchars($donnees['fichier']) ?>" 
									class="epi-photo" 
									alt="Photo de l'acquisition" 
									width="400">
								<?php endif; ?>
								<?php endif; ?>
								<?php if ($isAdmin) : ?>
								<input type='file' name='monfichier' accept='image/jpeg,image/png,image/gif, application/pdf'>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
				<div class="form-actions">
					
					<?php if ($enCours): ?>
					<a href="acquisition_effacer.php?id=<?= $donnees['acquisition_id'] ?>&retour=<?= $retour ?>"
						onclick="return confirm('Êtes-vous sûr de vouloir supprimer supprimer définitivement cette acquisition, cela supprimera tous les EPI liés ?')">
						<input type="button"  class="btn btn-danger" value="Annuler l'acquisition" name="supprimer">
					</a>
					<?php else: ?>
					<a href="<?= $retour ?>?csrf_token=<?= htmlspecialchars($csrf_token) ?>&id=<?= $id ?>" >
						<input type="button" name="retour" value="Retour" class="btn return-btn"></a>
					<?php endif; ?>
					<?php if ($isAdmin): ?>
					<button type="submit" name="envoyer" class="btn btn-primary">
						<?= ($enCours || ($_GET['edit'] == 'non')) ? 'Enregistrer les modifications' : 'Créer l'acquisition' ?>
					</button>
					<?php endif; ?>
					<a href="liste_acquisition.php?csrf_token=<?=$csrf_token?>&acquisition_id=<?=$acquisition_id?>&retour=<?=$retour?>&id=<?=$id?>">
						<?php if ($enCours): ?>
						<input type="button"  class="btn btn-primary" value="Saisir l'acquisition" name="saisir_acquisition">
						<?php else: ?>
						<input type="button"  class="btn btn-primary" value="Afficher les EPI" name="afficher_epi">
						<?php endif; ?>
					</a>
				</div>
			</form>
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