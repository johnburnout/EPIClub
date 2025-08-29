<?php
	
	// Inclusion des fichiers de configuration
	require __DIR__.'/config.php';
	require __DIR__."/includes/debug.php";
	require __DIR__."/includes/session.php";
	require __DIR__."/includes/fonctions_fichiers.php";	
	require __DIR__."/includes/bdd/liste_options.php";
	require __DIR__."/includes/bdd/lecture_fiche.php";
	require __DIR__."/includes/bdd/maj_fiche.php";
	
	
	// Vérification des permissions
	
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	$csrf_token = $_SESSION['csrf_token'] ?? '';

	if (!$isLoggedIn) {
    	header('Location: index.php?');
    	exit();
	}
	
	// #############################
	// Initialisation des variables
	// #############################
	
	$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
	$retour = isset($_GET['retour']) ? $_GET['retour'] : (isset($_POST['retour']) ? $_POST['retour'] : '');
	
	$action = isset($_GET['action']) ? $_GET['action'] : 'affichage' ;
	$action = isset($_POST['action']) ? $_POST['action'] : $action ;

	// #############################
	// Gestion des opérations CRUD
	// #############################
	//if ($isLoggedIn) {
	try {
		if ($id > 0) {
			
			// #############################
			// Récupération des listes d'options 
			// #############################
			
			$current_affectation_id = $donnees['affectation_id'] ?? 0;
			$current_categorie_id = $donnees['categorie_id'] ?? 0;
			$current_fabricant_id = $donnees['fabricant_id'] ?? 0;
			$listeaffectations = liste_options(['libelles' => 'affectation', 'id' => $current_affectation_id]);
			foreach ($listeaffectations[1] as $key => $value) {
				$affectations[$value['id']] = $value['libelle'];
			}
			$listeCategories = liste_options(['libelles' => 'categorie', 'id' => $current_categorie_id]);
			foreach ($listeCategories[1] as $key => $value) {
				$categories[$value['id']] = $value['libelle'];
			}
			$listeFabricants = liste_options(['libelles' => 'fabricant', 'id' => $current_fabricant_id]);
			foreach ($listeFabricants[1] as $key => $value) {
				$fabricants[$value['id']] = $value['libelle'];
			}
			$lecture = lecture_fiche($id);
			$donnees = $lecture['donnees'];
			$donneesInitiales = $donnees;
			
			
			$donnees['id'] = $id;
			if (!$lecture['success']) {
				throw new Exception('Erreur lors de la lecture: ' . ($result['error'] ?? ''));
			}
			
			//adresses journaux
			$journalmat = __DIR__.'/_storage/enregistrements/journalmat'.$donnees['reference'].'.txt';
			$journal = __DIR__.'/_storage/enregistrements/journal'.date('Y').'.txt';
			
			// Fusion des remarques
			$remarques_temp = [];
			if (!empty($donnees['remarques'])) {
				$remarques_temp[] = $donnees['remarques'];
			} 
			if (!empty($_POST['remarque'])) {
				$remarques_temp[] = $_POST['remarque'];
			}
			$remarques = implode(nl2br("\n"), $remarques_temp);
			$donnees['remarques'] = $remarques;
		}
		// Mise à jour des données avec les valeurs POST
		if (isset($_POST)) {
			foreach ($_POST as $key => $value) {
				if (array_key_exists($key, $donnees)) {
					$donnees[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
				}
			}
			$donnees['categorie'] = $categories[$donnees['categorie_id']];
			$donnees['affectation'] = $affectations[$donnees['affectation_id']];
			$donnees['fabricant'] = $fabricants[$donnees['fabricant_id']];
		}
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'validation') {
			$valid = mise_a_jour_fiche($donnees);
			if (!$valid['success']) {
				throw new Exception('Erreur lors de la mise à jour: ' . ($valid['error'] ?? ''));
			}
			
			// Journalisation des modifications
			$ajoutjournal = date('Y/m/d').' '.$utilisateur.PHP_EOL;
			
			$modifications = [];
			foreach ($donnees as $key => $value) {
				if (isset($donneesInitiales[$key]) && $donneesInitiales[$key] != $value) {
					$modifications[] = "$key modifié: ".$donneesInitiales[$key]." -> $value";
				}
			}
			
			if (!empty($modifications)) {
				// Construction du message de journalisation
				$ajoutjournal .= implode(PHP_EOL, $modifications) . PHP_EOL;
				
				// Ajout d'un timestamp pour le traçage
				$timestamp = '[' . date('Y-m-d H:i:s') . '] ';
				$ajoutjournal = $timestamp . $ajoutjournal;
				try {
					$reference = $donnees['reference'] ?? 'REF_INCONNUE';
					fichier_ecrire(['chemin' => $journal, 'texte' => "--EPI " . $reference . "--" . PHP_EOL . $ajoutjournal]);
					fichier_ecrire(['chemin' => $journalmat, 'texte' => "-------" . PHP_EOL . $ajoutjournal]);
				} catch (Exception $e) {
					// Journalisation de l'erreur et affichage convivial
					error_log("ERREUR Journalisation: " . $e->getMessage() . " \nStack Trace: " . $e->getTraceAsString());
					
					// Fermeture du handle si jamais il a été ouvert
					if (isset($handle)) {
						fclose($handle);
					}
				}
			}
		}
		
		// Gestion de l'upload de fichiers
		if (!empty($_FILES['monfichier']['name']) && $id > 0) {
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
			
			$uploadDir = __DIR__."/_storage/images/";
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
			
			$sql = "UPDATE matos SET photo = ? WHERE id = ?";
			$stmt = $connection->prepare($sql);
			$stmt->bind_param('si', $newFilename, $id);
			$stmt->execute();
			$donnees['photo'] = $newFilename;
			$connection->close();
		}
	} catch (Exception $e) {
		error_log("[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage());
		$errorMessage = $e->getMessage();
	}
	//}
	
	// #############################
	// Récupération des listes d'options
	// #############################
	$current_acquisition_id = $donnees['acquisition_id'] ?? 0;
	$current_affectation_id = $donnees['affectation_id'] ?? 0;
	$current_categorie_id = $donnees['categorie_id'] ?? 0;
	$current_fabricant_id = $donnees['fabricant_id'] ?? 0;
	
	$listeacquisitions = liste_options(['libelles' => 'acquisition', 'id' => $current_acquisition_id]);
	$listeaffectations = liste_options(['libelles' => 'affectation', 'id' => $current_affectation_id]);
	foreach ($listeaffectations[1] as $key => $value) {
		$affectations[$value['id']] = $value['libelle'];
	}
	$listeCategories = liste_options(['libelles' => 'categorie', 'id' => $current_categorie_id]);
	foreach ($listeCategories[1] as $key => $value) {
		$categories[$value['id']] = $value['libelle'];
	}
	$listeFabricants = liste_options(['libelles' => 'fabricant', 'id' => $current_fabricant_id]);
	foreach ($listeFabricants[1] as $key => $value) {
		$fabricants[$value['id']] = $value['libelle'];
	}
	// #############################
	// Préparation des données pour l'affichage
	// #############################
	$qrData = $site_url."/afficher_fiche.php?id=".$id;
	$viewData = [
		'affectation_id' => $isLoggedIn ? sprintf('<select name="affectation_id" required>%s</select>', $listeaffectations[0] ?? '') : htmlspecialchars($donnees['affectation'] ?? '', ENT_QUOTES, 'UTF-8'),
		'affectation' => htmlspecialchars($donnees['affectation'] ?? '', ENT_QUOTES, 'UTF-8'),
		'categorie_id' => $isAdmin ? sprintf('<select name="categorie_id" required>%s</select>', $listeCategories[0] ?? '') : htmlspecialchars($donnees['categorie'] ?? '', ENT_QUOTES, 'UTF-8'),
		'categorie' => htmlspecialchars($donnees['categorie'] ?? '', ENT_QUOTES, 'UTF-8'),
		'fabricant_id' => $isAdmin ? sprintf('<select name="fabricant_id" required>%s</select>', $listeFabricants[0] ?? '') : htmlspecialchars($donnees['fabricant'] ?? '', ENT_QUOTES, 'UTF-8'),
		'fabricant' => htmlspecialchars($donnees['fabricant'] ?? '', ENT_QUOTES, 'UTF-8'),
		'acquisition_id' => $isAdmin ? sprintf('<select name="acquisition_id">%s</select>', $listeacquisitions[0] ?? '') : htmlspecialchars($donnees['acquisition'] ?? '', ENT_QUOTES, 'UTF-8'),
		'acquisition' => htmlspecialchars($donnees['acquisition'] ?? '', ENT_QUOTES, 'UTF-8'),
		'libelle' => $isLoggedIn ? sprintf('<input name="libelle" type="text" required value="%s">', 
			htmlspecialchars($donnees['libelle'] ?? '', ENT_QUOTES, 'UTF-8')) : htmlspecialchars($donnees['libelle'] ?? '', ENT_QUOTES, 'UTF-8'),
		'date_debut' => $isAdmin ? sprintf('<input name="date_debut" type="date" required value="%s">', 
			htmlspecialchars(date('Y-m-d',strtotime($donnees['date_debut'])) ?? '', ENT_QUOTES, 'UTF-8')): htmlspecialchars(date('Y-m-d',strtotime($donnees['date_debut'])) ?? '', ENT_QUOTES, 'UTF-8'),
		'date_max' => $isAdmin ? sprintf('<input name="date_max" type="date" required value="%s">', 
			htmlspecialchars(date('Y-m-d',strtotime($donnees['date_max'])) ?? '', ENT_QUOTES, 'UTF-8')) : htmlspecialchars(date('Y-m-d',strtotime($donnees['date_max'])) ?? '', ENT_QUOTES, 'UTF-8'),
		'date_controle' => htmlspecialchars($donnees['date_controle']),
		'reference' => htmlspecialchars($donnees['reference'] ?? '', ENT_QUOTES, 'UTF-8'),
		'photo' => htmlspecialchars($donnees['photo'] ?? 'null.jpeg', ENT_QUOTES, 'UTF-8'),
		'remarques' => $donnees['remarques'] ?? '',
		'nb_elements_initial' => (int)($donnees['nb_elements_initial'] ?? 1),
		'action' => htmlspecialchars($action, ENT_QUOTES, 'UTF-8'),
		'id' => (int)$donnees['id'],
		'isEditMode' => true
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
			<form enctype="multipart/form-data" method="post" action="fiche_epi.php" id='form-controle'>
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="hidden" name="id" value="<?= $viewData['id'] ?>">
				<input type="hidden" name="affectation" value="<?= $viewData['affectation'] ?>">
				<input type="hidden" name="categorie" value="<?= $viewData['categorie'] ?>">
				<input type="hidden" name="fabricant" value="<?= $viewData['fabricant'] ?>">
				<input type="hidden" name="acquisition" value="<?= $viewData['acquisition'] ?>">
				<input type="hidden" name="appel_liste" value="0">
				<input type="hidden" name="action" value="validation">
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
							<td rowspan="10">
								<img src="_storage/images/<?= $viewData['photo'] ?>" class="epi-photo" alt="Photo du matériel" width="400">
								<?php if ($isLoggedIn): ?>
								<br>
								<input type="file" name="monfichier" accept="image/jpeg,image/png,image/gif">
								<?php endif; ?>
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
								<label for="affectation_id">Affectation :</label>
								<?= $viewData['affectation_id'] ?>
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
								<label for="fabricant_id">Fabricant :</label>
								<?= $viewData['fabricant_id'] ?>
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
								<label for="date_max">Date max :</label>
								<?= $viewData['date_max'] ?>
							</td>
						</tr>
						<tr>
							<td>
								<label for="date_controle">Date vérification :</label>
								<?= $viewData['date_controle'] ?>
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
								<label for="acquisition_id">acquisition :</label>
								<?= $viewData['acquisition_id'] ?>
							</td>
						</tr>
						<tr>
							<td colspan="1" rowspan="2">
								<label for="remarque">Remarques :</label>
							</td>
							<td>
								<p><?= $viewData['remarques'];?></p>
							</td>
						</tr>
						<?php if ($isLoggedIn): ?>
						<tr>
							<td>
								<textarea name="remarque" placeholder="Saisissez vos remarques..."  rows="4" cols="40"></textarea>
							</td>
						</tr>
						<tr>
							<td>
								<a href="qrcode.php?data=<?=$qrData?>&id=<?=$viewData['id']?>&display=1" target="_blank">
									<input type="button" name="qrcode" value="QR-Code" class="btn btn-primary"/>
								</a></td>
							<td>
								<a href="acquisition_verif.php?csrf_token=<?= $csrf_token ?>&retour=fiche_epi.php&id=<?= htmlspecialchars($viewData['id']) ?>&acquisition_id=<?= $donnees["acquisition_id"] ?>&edit=non" >
									<input type="button" name="acquisition" value="acquisition" class="btn btn-primary" />
								</a>
								<a href="affichage_texte.php?csrf_token=<?= $csrf_token ?>&url=<?= urlencode($journalmat) ?>&retour=fiche_epi.php&id=<?= htmlspecialchars($viewData['id']) ?>" >
		   							<input type="button" name="journal" value="Journal" class="btn btn-primary" />
								</a>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
				
				<div class="form-actions">
					<?php if ($viewData['isEditMode']): ?>
					<a href="fiche_effacer.php?id=<?= $viewData['id'] ?>&retour=<?= $retour ?>&csrf_token=<?=$csrf_token?>"
						onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette fiche ?')">
						<input type="button"  class="btn btn-danger" value="Supprimer la fiche" name="supprimer">
					</a>
					<?php endif; ?>
					<a href="liste_epis.php?csrf_token=<?=$csrf_token?>">
						<input type="button" value=<?= $viewData['isEditMode'] ? "Retour " : "Annuler" ;?> class="btn return-btn">
					</a>
					
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
				const remarques = this.elements['remarque'].value.trim();
				
				if (remarques.length > 500) {
					alert('Les remarques ne doivent pas dépasser 500 caractères.');
					e.preventDefault();
				}
			}
		);
	</script>
</html>