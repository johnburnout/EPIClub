<?php
	// Inclusion des fichiers de configuration
	require __DIR__.'/config.php';
	require __DIR__."/includes/debug.php";
	require __DIR__."/includes/session.php";
	require __DIR__."/includes/fonctions_fichiers.php";
	require __DIR__."/includes/bdd/liste_options.php";
	require __DIR__."/includes/bdd/lecture_fiche.php";
	require __DIR__."/includes/bdd/lecture_controle.php";
	require __DIR__."/includes/bdd/maj_fiche.php";
	
	
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	$csrf_token = $_SESSION['csrf_token'] ?? '';
	
	if (!$isLoggedIn) {
		header('Location: index.php?');
		exit();
	}
	
	// Initialisation des variables
	$retour = $_GET['retour'] ?? $_POST['retour'] ?? '';
	$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
	
	$donnees = [
		'reference' => '',
		'libelle' => '',
		'photo' => 'null.jpeg',
		'affectation_id' => 0,
		'categorie' => '',
		'date_debut' => date('Y-m-d'),
		'fabricant' => '',
		'nb_elements' => 0,
		'nb_elements_initial' => 0,
		'date_max' => '',
		'en_service' => 1,
		'remarques' => '',
		'remarque' => '',
		'controle_id' => $_SESSION['controle_en_cours'] ?? 0,
		'utilisateur' => $utilisateur
	];
	
	$remarques = '';
	$bouton = 'Valider le contrôle';
	
	// Traitement des données
	if ($id > 0) {
		$result = lecture_fiche($id);
		if ($result['success']) {
			$donnees = array_merge($donnees, $result['donnees']);
			$remarques = $donnees['remarques'];
		}
	}
	if ($donnees['controle_id'] == $_SESSION['controle_en_cours']) {
			header('Location: liste_controle.php?csrf_token='.$csrf_token);
			exit();
	}
	$donnees['controle_id'] = $_SESSION['controle_en_cours'] ?? $donnees['controle_id'];
	
	// Journalisation
	$journalmat = __DIR__.'/_storage/enregistrements/journalmat'.$donnees['reference'].'.txt';
	$journal = __DIR__.'/_storage/enregistrements/journal'.date('Y').'.txt';
	$journalcontrole = __DIR__.'/_storage/enregistrements/journalcontrole'.$donnees['controle_id'].'.txt';
	
	$donneesInitiales = $donnees;
	
	if (empty($_SESSION['epi_controles'])) {
		$controle = lecture_controle_recent($donnees['controle_id'], $utilisateur);
		$_SESSION['epi_controles'] = $controle['donnees']['epi_controles'] ?? '';
	}
	
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$bouton = 'Modifier le contrôle';
		
		// Traitement des données POST
		foreach ($_POST as $key => $value) {
			if (array_key_exists($key, $donnees)) {
				$donnees[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
			}
		}
	
		// Fusion des remarques
		$remarques = implode(nl2br("\n"), array_filter([
			$donnees['remarques'],
			$_POST['remarque'] ?? ''
		]));
		$donnees['remarques'] = $remarques;
	
		// Gestion des EPI contrôlés
		$epi_controles = array_unique(array_merge(
			explode(',', $_SESSION['epi_controles'] ?? ''),
			[$id]
		));
		$donnees['epi_controles'] = implode(',', array_filter($epi_controles));
		$_SESSION['epi_controles'] = $donnees['epi_controles'];
		
		$donnees['en_service'] = ($_POST['enservice'] ?? '') === '1' ? 1 : 0;
		$donnees['id'] = $id;
	
		// Validation
		if ($donnees['nb_elements'] > $donnees['nb_elements_initial']) {
			die('Erreur: Le nombre d\'éléments ne peut dépasser la quantité initiale');
		}
		
		
		if (($_POST['action'] ?? '') == 'validation') {
			try {
				$connection = new mysqli($host, $username, $password, $dbname);
				$connection->set_charset("utf8mb4");
				
				$sql = "UPDATE controle SET
						epi_controles = ?,
							utilisateur = ?
							WHERE id = ?";
				
				$stmt = $connection->prepare($sql);
				$stmt->bind_param("ssi", $donnees['epi_controles'], $donnees['utilisateur'] ,$donnees['controle_id']);
				$stmt->execute();
				
				if ($stmt->affected_rows > 0) {
					$valid = mise_a_jour_fiche($donnees);
					
					// Journalisation si succès
					if ($valid['success'] ?? false) {
						$modifications = [];
						foreach ($donnees as $key => $value) {
							if (isset($donneesInitiales[$key])) {
								$modifications[] = "$key modifié: ".$donneesInitiales[$key]." -> $value";
							}
						}
						
						$logContent = "-------\n[".date('Y-m-d H:i:s')."] "
							.$donnees['reference']." ".date('Y/m/d')." $utilisateur\n"
							.implode("\n", $modifications)."\n";
						
						try {
							fichier_ecrire(['chemin' => $journalcontrole, 'texte' => $logContent]);
							fichier_ecrire(['chemin' => $journal, 'texte' => $logContent]);
							fichier_ecrire(['chemin' => $journalmat, 'texte' => $logContent]);
						} catch (Throwable $e) {
							error_log("ERREUR Journalisation: ".$e->getMessage());
						}
					}
				}
			} catch(Exception $e) {
				error_log("Erreur lors de la mise à jour contrôle $id: ".$e->getMessage());
			} finally {
				$connection->close();
			}
		}
	}
	
	// Préparation des données pour l'affichage
	$current_affectation_id = $donnees['affectation_id'] ?? 0;
	$listeaffectations = liste_options(['libelles' => 'affectation', 'id' => $current_affectation_id]);
	$selectaffectations = $listeaffectations[0] ?? '';
	
	$enservice = [
		'1' => $donnees['en_service'] ? 'checked' : '',
		'0' => !$donnees['en_service'] ? 'checked' : ''
	];
	
	$avis = !$donnees['en_service'] 
		? '<div class="alert alert-warning">Attention: EPI non en service - merci d\'indiquer la raison dans les remarques.</div>' 
		: '';
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
			<?= $avis ?>
			<form method="post" action="controle_epi.php" enctype="multipart/form-data" id='form-controle'>
				<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
				<input type="hidden" name="id" value="<?= $id ?>">
				<input type="hidden" name="appel_liste" value="0">
				<input type="hidden" name="reference" value="<?= htmlspecialchars($donnees['reference'], ENT_QUOTES, 'UTF-8') ?>">		
				<input type="hidden" name="controle_id" value="<?= $_SESSION['controle_en_cours'] ?>">
				<input type="hidden" name="action" value="validation">
				<table>
					 <tbody>
						<tr>
								<th colspan="2">Informations de base</th>
						</tr>
						<tr>
								<td width="30%">Référence:</td>
								<td><?= htmlspecialchars($donnees['reference'], ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
						<tr>
								<td>Libellé:</td>
								<td><?= htmlspecialchars($donnees['libelle'], ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
						<tr>
								<td>Photo:</td>
								<td>
									 <img src="_storage/images/<?= htmlspecialchars($donnees['photo'], ENT_QUOTES, 'UTF-8') ?>" 
										class="epi-photo" 
										alt="Photo du matériel" width="300">
								</td>
						</tr>
						<tr>
								<td>affectation:</td>
								<td>
									 <select name="affectation_id">
										<?= $selectaffectations ?>
									 </select>
								</td>
						</tr>
						<tr>
								<td>Catégorie:</td>
								<td><?= htmlspecialchars($donnees['categorie'], ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
						<tr>
								<th colspan="2">Dates</th>
						</tr>
						<tr>
								<td>Date début:</td>
								<td><?= date('d/m/Y', strtotime($donnees['date_debut'])) ?></td>
						</tr>
						<tr>
								<td>Date max:</td>
								<td><?= date('d/m/Y', strtotime($donnees['date_max'])) ?></td>
						</tr>
						<tr>
								<th colspan="2">État</th>
						</tr>
						<tr>
								<td>Fabricant:</td>
								<td><?= htmlspecialchars($donnees['fabricant'], ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
						<tr>
								<td>Nombre d'éléments:</td>
								<td>
									 <input type="number" name="nb_elements" 
										value="<?= htmlspecialchars($donnees['nb_elements'], ENT_QUOTES, 'UTF-8') ?>" 
										min="0" max="<?= htmlspecialchars($donnees['nb_elements_initial'], ENT_QUOTES, 'UTF-8') ?>">
								</td>
						</tr>
						<tr>
								<td>En service:</td>
								<td>
									 <label>
										<input type="radio" name="enservice" value="1" <?= $enservice['1'] ?>> Oui
									 </label>
									 <label>
										<input type="radio" name="enservice" value="0" <?= $enservice['0'] ?>> Non
									 </label>
								</td>
						</tr>
						<tr>
								<th colspan="2">Remarques</th>
						</tr>
						<tr>
								<td colspan="2"><p><?= $donnees['remarques'];?></p></td>
						</tr>
						<tr>
								<td colspan="2">
									 <textarea name="remarque" placeholder="Saisissez vos remarques..." rows="4" cols="40"></textarea>
								</td>
						</tr>
					 </tbody>
				</table>
				
				<div class="form-actions">
					 <a href="liste_controle.php?csrf_token=<?=$csrf_token?>">
						<input type="button" value="Retour à la liste" class="btn return-btn">
					</a>
					 <input class="btn btn-primary" type="submit" name="envoyer" value=<?= $bouton ?>>
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