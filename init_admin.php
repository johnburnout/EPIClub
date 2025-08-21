<?php

	require __DIR__ . '/config.php';
	require __DIR__.'/includes/communs.php';
	require __DIR__.'/includes/fonctions_bdd_utilisateur.php';
	
	$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
	
	
	$retour = isset($_GET['retour']) ? $_GET['retour'] : (isset($_POST['retour']) ? $_POST['retour'] : 'liste_utilisateurs.php');
	$action = $_GET['action'] ?? $_POST['action'] ?? 'creation';
		// Valeurs par défaut pour les paramètres
	$donnees = [
		'username' => $_POST['username'] ?? 'user'.strval(rand(100000,999999)),		   
		'password' => $_POST['password'] ?? '',
		'email' => $_POST['email'] ?? 'username@epiclub',
		'role' => 'admin',
		'est_actif' => 1,  // Filtre "actif" par défaut (1 = oui)
		'id' => $id
	];
	 
	// ##############################################
	// CONNEXION À LA BASE DE DONNÉES AVEC GESTION D'ERREURS
	// ##############################################
	
	// Configuration du rapport d'erreurs MySQLi
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	
	try {
		// Création de la connexion MySQLi
		$connection = new mysqli($host, $username, $password, $dbname);
		
		// Définition du charset pour supporter tous les caractères (y compris émojis)
		$connection->set_charset("utf8mb4");
	} catch (mysqli_sql_exception $e) {
		// En production, vous pourriez logger cette erreur et afficher un message générique
		die("Erreur de connexion à la base de données: " . $e->getMessage());
	}
	
	// ##############################################
	// GESTION DES UTILISATEURS
	// ##############################################
	
	if (isset($_POST)) {
		$_POST['est_actif'] = empty($_POST['est_actif']) ? 1 : intval($_POST['est_actif']);
		try {
			if ($action == 'creation') {
				foreach ($_POST as $key => $value) {
					$donnees[$key] = $value;
				}
				$creation = creation_utilisateur($donnees);
				if (!$creation['success']) {
					throw new Exception("Erreur lors de la création de l'utilisateur : ". ($creation['error'] ?? ''));
				}
				$donnees['id'] = $creation['id'];
				$donnees['success'] = "Administrateur créé avec succès.";
			}
			if ($action === 'maj') {
				$maj = mise_a_jour_utilisateur($donnees, $donnees['id']);
				if (!$maj['success']) {
					throw new Exception("Erreur lors de la mise à jour de l'utilisateur: " . ($maj['error'] ?? ''));
				}
				$donnees['success'] = "Utilisateur mis à jour avec succès.";
			}
		}		catch (Exception $e) {
			error_log("[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage());
			$errorMessage = $e->getMessage();
		}
	}
	//lecture données utilisateur
	$lecture = lecture_utilisateur($donnees['id']);
	if (!$lecture['success']) {
					throw new Exception("Erreur lors de la lecture de l'utilisateur: " . ($lecture['error'] ?? ''));
				}
	$donnees = array_merge($donnees, $lecture['donnees']);
	$donnees['username'] = ($action == 'creation') ? 'username ?' : $donnees['username'];
	
	// ##############################################
	// PREPARATION DES DONNEES POUR L'AFFICHAGE
	// ##############################################
	
		
	$viewData = [
	    'username' => sprintf(
	        '<input class="form-control" name="username" type="text" required value="%s">',
	        htmlspecialchars($donnees['username'] ?? '', ENT_QUOTES, 'UTF-8')
	    ),
	    'password' => sprintf(
	        '<input class="form-control" name="password" type="password"%s>',
	        ($action != 'creation' ? ' placeholder="Laisser vide pour ne pas modifier"' : ' required')
	    ),
	    'password2' => sprintf(
	        '<input class="form-control" name="password2" type="password"%s>',
	        ($action != 'creation' ? ' placeholder="Confirmer le nouveau mot de passe"' : ' required')
	    ),
	    'email' => sprintf(
	        '<input class="form-control" name="email" type="email" required value="%s">',
	        htmlspecialchars($donnees['email'] ?? '', ENT_QUOTES, 'UTF-8')
	    ),
	    'id' => (int)($donnees['id'] ?? 0),
	    'est_actif' => (bool)($donnees['est_actif'] ?? false),
	    'isEditMode' => ($action !== 'creation')
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
			<?php if ($lecture['error']): ?>
			<div class="alert alert-error"><?= $lecture['error'] ?></div>
			<?php endif; ?>
			<?php if (!empty($maj['error'])): ?>
			<div class="alert alert-error"><?= $maj['error'] ?></div>
			<?php endif; ?>
			<form enctype="multipart/form-data" method="post" id='form-controle'>
				
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="hidden" name="action" value="maj">
				<input type="hidden" name="id" value="<?= $viewData['id'] ?>">
				<input type="hidden" name="est_actif" value="<?= $viewData['est_actif'] ?>">
				<input type="hidden" name="retour" value="liste_utilisateurs.php">
				<input type="hidden" name="role" value="admin">
				<table>
					<tbody>
						<tr>
						    <td>
						        <label for="username">Nom d'utilisateur :</label>
						        <div id="username-message" style="margin-top: 5px;">
						        	<?= $viewData['username'] ?>
						        </div>
						    </td>
						</tr>
						<tr>
							<td>
								<label for="email">e-mail :</label>
								<?= $viewData['email'] ?>
							</td>
						</tr>
						<tr>
							<td>
								<label for="password">Mot de passe :</label>
								<?= $viewData['password'] ?>
							</td>
						</tr>
						<tr>
							<td>
								<label for="password2">Mot de passe (confirmation) :</label>
								<?= $viewData['password2'] ?>
							</td>
						</tr>
					</tbody>
				</table>
				<div class="form-actions">
					<?php if ($viewData['isEditMode']): ?>
					<a href="utilisateur_effacer.php?id=<?= $viewData['id'] ?>&retour=<?= $retour ?>&csrf_token=<?=$csrf_token?>"
						onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')">
						<input type="button"  class="btn btn-danger" value="Supprimer l'utilisateur" name="supprimer">
					</a>
					<?php endif; ?>
					<a href="<?= $viewData['isEditMode'] ? $retour : 'utilisateur_effacer.php' ?>?csrf_token=<?=$csrf_token?>&edit=<?=$viewData['isEditMode'] ? 0 : 1 ?>&id=<?=$donnees['id']?>">
						<input type="button" value=<?= $viewData['isEditMode'] ? "Retour " : "Annuler" ;?> class="btn btn-secondary">
					</a>
					
					<button type="submit" name="envoyer" class="btn btn-primary">
						<?= $viewData['isEditMode'] ? 'Mettre à jour' : 'Créer' ?> l'utilisateur
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
			const password = this.elements['password'].value;
			const password2 = this.elements['password2'].value;
			
			if (password != password2 ) {
				alert('Les mots de passe ne sont pas identiques.');
				e.preventDefault();
			}
		});
	</script>
</html>