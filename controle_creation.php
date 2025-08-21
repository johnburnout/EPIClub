<?php
	
	// Inclusion des fichiers de configuration
	require __DIR__ . '/config.php';
	require __DIR__.'/includes/communs.php';
	require __DIR__.'/includes/fonctions_bdd_controle.php';
	
	// Vérification des permissions
	// Validation CSRF
	if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
    	throw new Exception('Erreur de sécurité: Token CSRF invalide');
	}

	if (!$isLoggedIn) {
    	header('Location: index.php?');
    	exit();
	}
	
	if (!isset($_POST) or count($_POST) == 0) {
		header("Location: index.php");
		exit;
	}
	
	// #############################
	// Initialisation des variables
	// #############################
	$action =  isset($_POST['action']) ? $_POST['action'] : 'creation';
	$validation = $action;	
	
	$defaults = [
		'action' => 'creation',
		'controle_id' => 0,
		'utilisateur' => $utilisateur,
		'remarques' => '',
		'date_verification' => date('Y-m-d'),
		'error' => '',
		'success' => '',
		'epi_controles' => ''
	];
	
	foreach ($defaults as $key => $value) {
		$donnees[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?? $value;
		if (in_array($key, ['controle_id'])) {
			$donnees[$key] = (int)$donnees[$key];
		}
	}
	
	// Initialisation des données
	
	// Récupération de l'ID
	$donnees['controle_id'] = isset($_SESSION['controle_en_cours']) ? intval($_SESSION['controle_en_cours']) : 0 ;
	$isStarted = ($donnees['controle_id'] != 0);
	
	// #############################
	// Gestion des opérations CRUD
	// #############################
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		try{
	
			if ($action === 'creation') {
				$creation = creation_controle([
					'utilisateur' => $utilisateur,
					'date_verification' => $donnees['date_verification']
				]);
				if (!$creation['success']) {
					throw new Exception('Erreur lors de la création: ' . ($creation['error'] ?? ''));
				}
				$donnees['controle_id'] = $creation['id'];
				$_SESSION['controle_en_cours'] = $donnees['controle_id'];
				$_SESSION['epi_controles'] = "";
				$isStarted = true;
				$donnees['success'] = "Nouveau contrôle créé avec succès.";
			}
			
			// Lecture des données après création/mise à jour
			if ($donnees['controle_id'] > 0) {
				$result = lecture_controle($donnees['controle_id'], $utilisateur);
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
					$donnees['remarques'] = isset($_POST['remarques']) 
					? htmlspecialchars(trim($_POST['remarques']), ENT_QUOTES, 'UTF-8') 
					: '';
					$maj = mise_a_jour_controle([
						'remarques' => $donnees['remarques'],
						'utilisateur' => $utilisateur,
						'epi_controles' => $_SESSION['epi_controles']
					], $donnees['controle_id']);
					if (!$maj['success']) {
						throw new Exception('Erreur lors de la mise à jour: ' . ($valid['error'] ?? ''));
					}
				}
			}
			$action = 'maj';
		} catch (Exception $e) {
			error_log("[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage());
			$errorMessage = $e->getMessage();
		}
		
		// Journalisation
		if (isset($maj['success']) or isset($creation['success'])) {
			// Vérification des chemins avant écriture
			$allowedPath = __DIR__.'/utilisateur/enregistrements/';
			//adresse journaux
			$journalcontrole = __DIR__.'/utilisateur/enregistrements/journalcontrole'.$donnees['controle_id'].'.txt';
			$journal = __DIR__.'/utilisateur/enregistrements/journal'.date('Y').'.txt';
			
			if (strpos($journalcontrole, $allowedPath) === 0 && strpos($journal, $allowedPath) === 0) {
				$modifications = [];
				
				foreach ($donneesInitiales as $key => $value) {
					if (isset($donnees[$key]) && $donnees[$key] != $value) {
						$modifications[] = "$key modifié: ".$donnees[$key]." -> $value";
					}
				}
				
				$ajoutjournal = '-----'.PHP_EOL."controle_".$donnees['controle_id']."_".date('Y/m/d')." $utilisateur".PHP_EOL;
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

	$viewData = [
		'date_verification' => $donnees['date_verification'],
		'remarques' => htmlspecialchars($donnees['remarques'] ?? '', ENT_QUOTES, 'UTF-8'),
		'action' => htmlspecialchars($action, ENT_QUOTES, 'UTF-8'),
		'controle_id' => (int)$donnees['controle_id'],
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
			<?php if ($donnees['error']): ?>
			<div class="alert alert-error"><?= $donnees['error'] ?></div>
			<?php endif; ?>
			<form method="post" id="form-controle">
			<?php if ($donnees['success']): ?>
			<div class="alert alert-success"><?= $donnees['success'] ?></div>
		<?php endif; ?>
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="hidden" name="action" value="<?= $isStarted ? 'maj' : 'creation' ?>">
				<input type="hidden" name="controle_id" value="<?= $donnees['controle_id'] ?>">
				
				<table>
					<tbody>
						<tr>
							<th width="20%">Utilisateur</th>
							<td width="30%"><?= $utilisateur ?></td>
							<th width="20%">Date</th>
							<td width="30%"><?= $viewData['date_verification'] ?></td>
						</tr>
						<tr>
							<th colspan="4">Remarques et Observations</th>
						</tr>
						<tr>
							<td colspan="4">
								<textarea name="remarques" placeholder="Saisissez vos observations..." rows="4" cols="40"><?= $donnees['remarques'] ?></textarea>
							</td>
						</tr>
					</tbody>
				</table>
				
				<div class="form-actions">
					
					<?php if ($isStarted): ?>
					<a href="controle_effacer.php?id=<?= $donnees['controle_id'] ?>&csrf_token=<?=$csrf_token?>"
						onclick="return confirm('Êtes-vous sûr de vouloir supprimer supprimer définitivement ce contrôle ?')">
						<input type="button"  class="btn btn-danger" value="Annuler le contrôle" name="supprimer">
					</a>
					<?php else: ?>
					<a href="index.php" class="btn btn-secondary">Retour</a>
					<?php endif; ?>
					
					<button type="submit" name="envoyer" class="btn btn-primary">
						<?= $isStarted ? 'Enregistrer les modifications' : 'Créer le contrôle' ?>
					</button>`
					<?php if ($isStarted): ?>
					<a href="liste_controle.php?csrf_token=<?=$csrf_token?>"
						onclick="return confirm('Êtes-vous prêt à commencer contrôle ?')">
						<input type="button"  class="btn btn-primary" value="Commencer le contrôle" name="controler">
					</a>
					<?php endif; ?>
				</div>
				<div class="actions">
				<a href="index.php" >
					<input type="button" name="retour" value="Retour à l'accueil" class="btn return-btn" /></a>
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