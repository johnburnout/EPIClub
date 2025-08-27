<?php

	require __DIR__.'/config.php';
	require __DIR__."/includes/debug.php";
	require __DIR__."/includes/session.php";
	require __DIR__."/includes/bdd/creation_affectation.php";	
	require __DIR__."/includes/bdd/lecture_affectation.php";	
	require __DIR__."/includes/bdd/maj_affectation.php";
	
	// Vérification des permissions
	// Validation CSRF
	if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
    	throw new Exception('Erreur de sécurité: Token CSRF invalide');
	}

	if (!$isAdmin) {
    	header('Location: index.php?');
    	exit();
	}
	
	$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
	$retour = isset($_GET['retour']) ? $_GET['retour'] : (isset($_POST['retour']) ? $_POST['retour'] : '');
	$action = $_GET['action'] ?? $_POST['action'] ?? 'maj';
		// Valeurs par défaut pour les paramètres
	$donnees = [
		'libelle' => 'fab'.strval(rand(100000,999999)),
		'id' => $id
	];
	
	// ##############################################
	// GESTION DES UTILISATEURS
	// ##############################################
	
	if (!empty($_POST)) {
		try {
			foreach ($_POST as $key => $value) {
				$donnees[$key] = $value;
			}
			if ($action === 'creation') {
				$creation = creation_affectation($donnees);
				if (!$creation['success']) {
					throw new Exception('Erreur lors de la création du affectation: ' . ($creation['error'] ?? ''));
				}
				$donnees['id'] = $creation['id'];
				$donnees['success'] = "Nouveau affectation créé avec succès.";
			}
	
			if ($action === 'maj') {
				$maj = mise_a_jour_affectation($donnees, $donnees['id']);
				if (!$maj['success']) {
					throw new Exception("Erreur lors de la mise à jour du affectation: " . ($maj['error'] ?? ''));
				}
				$donnees['success'] = "affectation mis à jour avec succès.";
			}
		}
		catch (Exception $e) {
			error_log("[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage());
		}
	}	
	//lecture données utilisateur
	$lecture = lecture_affectation($donnees['id']);
	if (!$lecture['success']) {
					throw new Exception("Erreur lors de la lecture du affectation : " . ($lecture['error'] ?? ''));
				}
	$donnees = array_merge($donnees, $lecture['donnees']);
	
	// ##############################################
	// PREPARATION DES DONNEES POUR L'AFFICHAGE
	// ##############################################
	
		
	$viewData = [
		'libelle' =>  
			sprintf('<input name="libelle" type="text" required value="%s">', 
			htmlspecialchars(($action == 'maj') ? $donnees['libelle'] : 'affectation ?', ENT_QUOTES, 'UTF-8')),
		'id' => (int)$donnees['id'],
		'isEditMode' => $action == 'maj' ? true : false
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
			<?php if (!empty($maj['error'])): ?>
			<div class="alert alert-error"><?= $maj['error'] ?></div>
			<?php endif; ?>
			<?php if (!empty($maj['success'])): ?>
			<div class="alert alert-success">Mise à jour réussie</div>
			<?php endif; ?>
			<?php if (!empty($creation['error'])): ?>
			<div class="alert alert-error"><?= $creation['error'] ?></div>
			<?php endif; ?>
			<?php if (!empty($creation['success'])): ?>
			<div class="alert alert-succes">Création de fiche réussie</div>
			<?php endif; ?>
			<form enctype="multipart/form-data" method="post" action="fiche_affectation.php" id='form-controle'>
				
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="hidden" name="action" value="maj">
				<input type="hidden" name="id" value="<?= $viewData['id'] ?>">
				<input type="hidden" name="retour" value="liste_affectations.php">
				<table>
					<tbody>
						<tr>
							<td>
								<label for="libelle">Nom du affectation :</label>
								<?= $viewData['libelle'] ?>
							</td>
						</tr>
					</tbody>
				</table>
				<div class="form-actions">
					<?php if ($viewData['isEditMode']): ?>
					<a href="affectation_effacer.php?id=<?= $viewData['id'] ?>&retour=<?= $retour ?>&csrf_token=<?=$csrf_token?>"
						onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce affectation ?')">
						<input type="button"  class="btn btn-danger" value="Supprimer le affectation" name="supprimer">
					</a>
					<?php endif; ?>
					<a href="<?= $viewData['isEditMode'] ? $retour : 'affectation_effacer.php' ?>?csrf_token=<?=$csrf_token?>&edit=<?=$viewData['isEditMode'] ? 0 : 1 ?>&id=<?=$donnees['id']?>">
						<input type="button" value=<?= $viewData['isEditMode'] ? "Retour " : "Annuler" ;?> class="btn return-btn">
					</a>
					
					<button type="submit" name="envoyer" class="btn btn-primary">
						<?= $viewData['isEditMode'] ? 'Mettre à jour' : 'Créer'; ?> le affectation
					</button>
				</div>
			</form>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>