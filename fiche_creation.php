<?php	
	
	// Inclusion des fichiers de configuration
	require __DIR__ . '/config.php';
	require __DIR__.'/includes/communs.php';
	require __DIR__.'/includes/init_donnees.php';
	require __DIR__.'/includes/fonctions_bdd_fiche.php';
	
	
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
	// Initialisation des variables
	// #############################
	$action =  isset($_POST['action']) ? $_POST['action'] : 'creation';
	$validation = $action;
	
	$defaults = [
		'id' => isset($_POST['id']) ? $_POST['id'] : 0,
		'facture_id' => $_SESSION['facture_en_saisie'] ? intval($_SESSION['facture_en_saisie']) : 1,
		'reference' => date('y').strval(rand(100000,999999)),
		'libelle' => '',
		'photo' => 'null.jpeg',
		'lieu_id' => 1,
		'categorie_id' => 1,
		'date_debut' => date('Y-m-d'),
		'fabricant_id' => 1,
		'nb_elements_initial' => 1,
		'remarques' => '',
		'appel_liste' => 0,
		'retour' => isset($_POST['retour']) ? $_POST['retour'] : 'index.php'
	];
	$retour = $defaults['retour'];
	// Initialisation des données
	
	foreach ($defaults as $key => $value) {
		$donnees[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?? $value;
		if (in_array($key, ['id', 'facture_id', 'lieu_id', 'categorie_id', 'fabricant_id', 'nb_elements_initial', 'appel_liste'])) {
			$donnees[$key] = (int)$donnees[$key];
		}
	}
	
	// #############################
	// Gestion des opérations CRUD
	// #############################
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		try {
			
			if ($action === 'creation') {
				$creation = creation_fiche($donnees);
				if (!$creation['success']) {
					throw new Exception('Erreur lors de la création: ' . ($creation['error'] ?? ''));
				}
				$donnees['id'] = $creation['id'];
			}
			
			// Lecture des données après création/mise à jour
			if ($donnees['id'] > 0) {
				$result = lecture_fiche($donnees['id']);
				if (!$result['success']) {
					throw new Exception('Erreur lors de la lecture: ' . ($result['error'] ?? ''));
				}
				$donnees = array_merge($donnees, $result['donnees']);
				$donneesInitiales = $donnees;
				
				// #############################
				// Récupération des listes d'options 
				// #############################
				
				$current_lieu_id = $donnees['lieu_id'] ?? 0;
				$current_categorie_id = $donnees['categorie_id'] ?? 0;
				$current_fabricant_id = $donnees['fabricant_id'] ?? 0;
				$listeLieux = liste_options(['libelles' => 'lieu', 'id' => $current_lieu_id]);
				foreach ($listeLieux[1] as $key => $value) {
					$lieux[$value['id']] = $value['libelle'];
				}
				$listeCategories = liste_options(['libelles' => 'categorie', 'id' => $current_categorie_id]);
				foreach ($listeCategories[1] as $key => $value) {
					$categories[$value['id']] = $value['libelle'];
				}
				$listeFabricants = liste_options(['libelles' => 'fabricant', 'id' => $current_fabricant_id]);
				foreach ($listeFabricants[1] as $key => $value) {
					$fabricants[$value['id']] = $value['libelle'];
				}
				if ($action === 'maj') {
					foreach ($donnees as $key => $value) {
						$donnees[$key] = isset($_POST[$key]) ? $_POST[$key] : $donnees[$key];
					}
					$donnees['categorie'] = $categories[$donnees['categorie_id']];
					$donnees['lieu'] = $lieux[$donnees['lieu_id']];
					$donnees['fabricant'] = $fabricants[$donnees['fabricant_id']];
					$valid = mise_a_jour_fiche($donnees);
					if (!$valid['success']) {
						throw new Exception('Erreur lors de la mise à jour: ' . ($valid['error'] ?? ''));
					}
				}
			}
			// Gestion de l'upload de fichiers
			if (!empty($_FILES['monfichier']['name']) && $_POST['id'] > 0) {
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
				
				$uploadDir = __DIR__."/images/";
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
				
				$sql = "UPDATE matos SET photo = ? WHERE id = ?";
				$stmt = $connection->prepare($sql);
				$stmt->bind_param('si', $newFilename, $_POST['id']);
				$stmt->execute();
				$donnees['photo'] = $newFilename;
				$connection->close();
			}
		} catch (Exception $e) {
			error_log("[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage());
			$errorMessage = $e->getMessage();
		}
		// #############################
		// Récupération des listes d'options 
		// #############################
		$current_facture_id = $donnees['facture_id'] ?? 0;
		$current_lieu_id = $donnees['lieu_id'] ?? 0;
		$current_categorie_id = $donnees['categorie_id'] ?? 0;
		$current_fabricant_id = $donnees['fabricant_id'] ?? 0;
		
		$listeFactures = liste_options(['libelles' => 'facture', 'id' => $current_facture_id]);
		$listeLieux = liste_options(['libelles' => 'lieu', 'id' => $current_lieu_id]);
		foreach ($listeLieux[1] as $key => $value) {
			$lieux[$value['id']] = $value['libelle'];
		}
		$listeCategories = liste_options(['libelles' => 'categorie', 'id' => $current_categorie_id]);
		foreach ($listeCategories[1] as $key => $value) {
			$categories[$value['id']] = $value['libelle'];
		}
		$listeFabricants = liste_options(['libelles' => 'fabricant', 'id' => $current_fabricant_id]);
		foreach ($listeFabricants[1] as $key => $value) {
			$fabricants[$value['id']] = $value['libelle'];
		}
		// Journalisation
		if (isset($valid['success']) or isset($creation['success'])) {
			$reference = $donnees['reference'];
			
			//adresses journaux
			$journalcontrole = __DIR__.'/enregistrements/journal'.$donnees['reference'].'.txt';
			$journal = __DIR__.'/enregistrements/journal'.date('Y').'.txt';
	
			// Vérification des chemins avant écriture
			$allowedPath = __DIR__.'/enregistrements/';
			if (strpos($journalcontrole, $allowedPath) === 0 && strpos($journal, $allowedPath) === 0) {
				$modifications = [];
				
				foreach ($donnees as $key => $value) {
					if (isset($donneesInitiales[$key]) && $donneesInitiales[$key] != $value) {
						$modifications[] = "$key modifié: ".$donneesInitiales[$key]." -> $value";
					}
				}
				$ajoutjournal = '-----'.PHP_EOL."$reference ".date('Y/m/d')." $utilisateur".PHP_EOL;
				if (!empty($modifications)) {
					$ajoutjournal .= implode(PHP_EOL, $modifications).PHP_EOL;
				}
				
				try {
					fichier_ecrire(['chemin' => $journalcontrole, 'texte' => $ajoutjournal]);
					fichier_ecrire(['chemin' => $journal, 'texte' => $ajoutjournal]);
				} catch (Exception $e) {
					error_log("Erreur journalisation: ".$e->getMessage());
				}
			}
		}
	}
	
	// #############################
	// Préparation des données pour l'affichage
	// #############################
	
	$viewData = [
		'lieu_id' => sprintf('<select name="lieu_id" required>%s</select>', $listeLieux[0] ?? ''),
		'lieu' => htmlspecialchars($lieux[$current_lieu_id] ?? '', ENT_QUOTES, 'UTF-8'),
		'categorie_id' => sprintf('<select name="categorie_id" required>%s</select>', $listeCategories[0] ?? ''),
		'categorie' => htmlspecialchars($categories[$current_categorie_id] ?? '', ENT_QUOTES, 'UTF-8'),
		'fabricant_id' => sprintf('<select name="fabricant_id" required>%s</select>', $listeFabricants[0] ?? ''),
		'fabricant' => htmlspecialchars($fabricants[$current_fabricant_id] ?? '', ENT_QUOTES, 'UTF-8'),
		'facture_id' => sprintf('<select name="facture_id">%s</select>', $listeFactures[0] ?? ''),
		'facture' => htmlspecialchars($donnees['facture'] ?? '', ENT_QUOTES, 'UTF-8'),
		'libelle' => sprintf('<input name="libelle" type="text" required value="%s">', 
			htmlspecialchars($donnees['libelle'] ?? '', ENT_QUOTES, 'UTF-8')),
		'date_debut' => sprintf('<input name="date_debut" type="date" required value="%s">', 
			date('Y-m-d')),
		'reference' => htmlspecialchars($donnees['reference'] ?? '', ENT_QUOTES, 'UTF-8'),
		'photo' => htmlspecialchars($donnees['photo'] ?? 'null.jpeg', ENT_QUOTES, 'UTF-8'),
		'remarques' => htmlspecialchars($donnees['remarques'] ?? '', ENT_QUOTES, 'UTF-8'),
		'nb_elements_initial' => (int)($donnees['nb_elements_initial'] ?? 1),
		'action' => "maj",
		'id' => (int)$donnees['id'],
		'retour' => $donnees['retour'],
		'isEditMode' => $validation === 'maj',
		'isNewMode' => $validation === 'creation'
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
			<?php if (isset($errorMessage)): ?>
			<div class="alert alert-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
			<?php endif; ?>
			<form enctype="multipart/form-data" method="post" action="fiche_creation.php" id='form-controle'>
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="hidden" name="action" value="maj">
				<input type="hidden" name="id" value="<?= $viewData['id'] ?>">
				<input type="hidden" name="categorie" value="<?= $viewData['categorie'] ?>">
				<input type="hidden" name="fabricant" value="<?= $viewData['fabricant'] ?>">
				<input type="hidden" name="lieu" value="<?= $viewData['lieu'] ?>">
				<input type="hidden" name="facture" value="<?= $viewData['facture'] ?>">
				<input type="hidden" name="retour" value="<?= $viewData['retour'] ?>">
				<input type="hidden" name="appel_liste" value="0">
				<input type="hidden" name="MAX_FILE_SIZE" value="2000000">
				
				<table>
					<tbody>
						<tr>
							<th colspan="2">Informations de base</th>
						</tr>
						<tr>
							<td width="30%">
								<label for="reference">Référence :</label>
								<input type="text" name="reference" required value="<?= $viewData['reference'] ?>">
							</td>
							<td rowspan="7">
								<img src="images/<?= $viewData['photo'] ?>" class="epi-photo" alt="Photo du matériel" width="400">
								<br>
								<input type="file" class="btn btn-secondary" name="monfichier" accept="image/jpeg,image/png,image/gif">
							</td>
						</tr>
						<tr>
							<td>
								<label for="libelle">Libellé :</label>
								<?= $viewData['libelle'] ?>
							</td>
						</tr>
						<tr>
							<td>
								<label for="lieu_id">Lieu :</label>
								<?= $viewData['lieu_id'] ?>
							</td>
						</tr>
						<tr>
							<td>
								<label for="categorie_id">Catégorie :</label>
								<?= $viewData['categorie_id'] ?>
							</td>
						</tr>
						<tr>
							<td>
								<label for="date_debut">Mise en service :</label>
								<?= $viewData['date_debut'] ?>
							</td>
						</tr>
						<tr>
							<td>
								<label for="fabricant_id">Fabricant :</label>
								<?= $viewData['fabricant_id'] ?>
							</td>
						</tr>
						<tr>
							<td colspan="1">
								<label for="nb_elements_initial">Nombre d'éléments :</label>
								<input type="number" name="nb_elements_initial" 
									value="<?= $viewData['nb_elements_initial'] ?>" min="1" required>
							</td>
						</tr>
						<tr>
							<td colspan="1">
								Facture :
							</td>
							<td>
								<?= $viewData['facture'] ?>
							</td>
						</tr>
						<tr>
							<td colspan="1">
								<label for="remarques">Remarques :</label>
							</td>
							<td>
								<textarea name="remarques" placeholder="Saisissez vos remarques..."  rows="4" cols="40"><?= $viewData['remarques'] ?></textarea>
							</td>
						</tr>
					</tbody>
				</table>
				
				<div class="form-actions">
					<?php if ($viewData['isEditMode']): ?>
					<a href="<?=$viewData['retour'];?>?csrf_token=<?=$csrf_token?>">
						<input type="button" value="Retour " class="btn btn-secondary">
					</a>
					<a href="fiche_effacer.php?id=<?= $viewData['id'] ?>&retour=<?= $retour ?>&csrf_token=<?=$csrf_token?>"
						onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette fiche ?')">
						<input type="button"  class="btn btn-danger" value="Supprimer la fiche" name="supprimer">
					</a>
					<?php else: ?>
					<a href="<?=$retour?>?csrf_token=<?=$csrf_token?>">
						<input type="button" value="Annuler" class="btn btn-secondary">
					</a>
					<?php endif; ?>
					
					<button type="submit" name="envoyer" class="btn btn-primary">
						<?= $viewData['isEditMode'] ? 'Mettre à jour' : 'Créer' ?> la fiche
					</button>
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