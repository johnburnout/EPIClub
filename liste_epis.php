<?php
	
	require __DIR__.'/config.php';
	require __DIR__."/includes/debug.php";
	require __DIR__."/includes/session.php";
	require __DIR__."/includes/bdd/liste_options.php"; 
	require __DIR__."/includes/fonctions_edition.php";
	
	// Vérification des permissions
	// Validation CSRF
	if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
		throw new Exception('Erreur de sécurité: Token CSRF invalide');
	}
	
	if (!$isLoggedIn) {
		header('Location: index.php?');
		exit();
	}
	
	if (isset($_POST['id'])) {
		header('Location: fiche_epi.php?id='.$_POST['id'].'&action=affichage&retour=liste_epis.php&csrf_token='.$csrf_token);
		exit();
	};
	
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
	// GESTION DE LA PAGINATION ET DES FILTRES
	// ##############################################
	
	// Valeurs par défaut pour les paramètres
	$defaults = [
		'debut' => 1,			// Première ligne à afficher
		'long' => 20,			// Nombre de lignes par page
		'nblignes' => 20,		// Nombre total de lignes
		'id' => 1,			   // ID par défaut
		'affectation_id' => 0,		  // Filtre affectation (0 = tous)
		'cat_id' => 0,		   // Filtre catégorie (0 = tous)
		'tri' => 'id',		   // Champ de tri par défaut
		'est_en_service' => '1'  // Filtre "en service" par défaut (1 = oui)
	];
	
	// Traitement des paramètres de requête
	$params = [];
	foreach ($defaults as $key => $default) {
		if (isset($_POST[$key])) {
			// Nettoie l'entrée selon son type (int ou string)
			$params[$key] = sanitizeInput($_POST[$key], is_numeric($default) ? 'int' : 'string');
			} else {
				// Utilise la valeur par défaut si le paramètre n'est pas fourni
				$params[$key] = $default;  // Correction ici: $default au affectation de $default[$key]
			}
				}
				// Gestion des cookies pour conserver les préférences utilisateur
				if ($_SERVER['REQUEST_METHOD'] === 'POST') {
					// Enregistre les préférences dans des cookies sécurisés
					$_SESSION['debut'] = $params['debut'];
					$_SESSION['long'] = $params['long'];
					$_SESSION['nblignes'] = $params['nblignes'];
				} else {
					// Récupère les préférences depuis les cookies ou utilise les valeurs par défaut
					$params['debut'] = $_SESSION['debut'] ?? $defaults['debut'];
					$params['long'] = $_SESSION['long'] ?? $defaults['long'];
					$params['nblignes'] = $_SESSION['nblignes'] ?? $defaults['nblignes'];
				}
				// ##############################################
				// PRÉPARATION DES LISTES D'OPTIONS
				// ##############################################
				
				// Génère les listes déroulantes pour les affectations et catégories
				// Note: liste_options() est probablement définie dans common.php
				$listeaffectations = liste_options(['libelles' => 'affectation', 'id' => $params['affectation_id']]);
				$listeaffectations[0] = "<option value='*'>Tous</option>".$listeaffectations[0];
				$listeCategories = liste_options(['libelles' => 'categorie', 'id' => $params['cat_id']]);
				$listeCategories[0] = "<option value='*'>Toutes</option>".$listeCategories[0];
				
				// Options pour le filtre "en service"
				$enservice = [
					'oui' => (!isset($_POST['est_en_service']) || $_POST['est_en_service'] == '1') ? '"1" selected' : '"1"',
					'non' => (isset($_POST['est_en_service']) && $_POST['est_en_service'] == '0') ? '"0" selected' : '"0"',
					'*' => (isset($_POST['est_en_service']) && $_POST['est_en_service'] == '*') ? '"*" selected' : '"*"'
				];
				
				// ##############################################
				// CONSTRUCTION DE LA REQUÊTE SQL SÉCURISÉE
				// ##############################################
				
				$whereClauses = [];  // Conditions WHERE
				$queryParams = [];   // Paramètres pour la requête préparée
				$types = '';		 // Types des paramètres (i = integer, s = string)
				
				// Construction dynamique de la clause WHERE
				if ($params['affectation_id'] > 0) {
					$whereClauses[] = "affectation_id = ?";
					$queryParams[] = $params['affectation_id'];
					$types .= 'i';  // Type integer
				}
				
				if ($params['cat_id'] > 0) {
					$whereClauses[] = "categorie_id = ?";
					$queryParams[] = $params['cat_id'];
					$types .= 'i';  // Type integer
				}
				
				// Filtre "en service" (toujours présent)
				$whereClauses[] = "en_service = ?";
				$queryParams[] = (int)$params['est_en_service'];
				$types .= 'i';  // Type string
				
				//*************
				
				// Combinaison des conditions WHERE
				$where = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);
				try {
					// Requête pour le comptage total
					$countSql = "SELECT COUNT(*) AS total FROM liste $where";
					$countStmt = $connection->prepare($countSql);
					
					if (!empty($queryParams)) {
						$countStmt->bind_param($types, ...$queryParams);
					}
					
					$countStmt->execute();
					$totalCount = $countStmt->get_result()->fetch_assoc()['total'];
					$nblignes = (int)$totalCount;
					$nbpages = ceil($nblignes / max(1, $params['long']));
					
				} catch (mysqli_sql_exception $e) {
					die("Erreur lors de l'exécution de la requête: " . $e->getMessage());
				}
				
				$types .= 'ii';
				$queryParams[] = (int)$params['debut'] - 1;
				$queryParams[] = (int)$params['long'];
				
				// Validation du champ de tri (whitelist)
				$allowedSort = ['id', 'ref', 'affectation_id', 'date_controle', 'fabricant'];
				$sort = in_array($params['tri'], $allowedSort) ? $params['tri'] : 'id';
				
				// Exécution de la requête principale
				try {
					$sql = "SELECT id, ref, libelle, fabricant, categorie, categorie_id, 
					affectation, affectation_id, nb_elements, date_controle, date_max
					FROM liste $where ORDER BY $sort LIMIT ?, ?";
					
					$stmt = $connection->prepare($sql);
					
					// Liaison des paramètres si nécessaire
					if (!empty($queryParams)) {
						$stmt->bind_param($types, ...$queryParams);
					}
					
					// Exécution et récupération des résultats
					$stmt->execute();
					$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
					//$nblignes = $params['nblignes'];
					
					// Calcul de la pagination
					$nbpages = intdiv($nblignes, $params['long']) + (($nblignes % $params['long']) > 0);
				} catch (mysqli_sql_exception $e) {
					die("Erreur lors de l'exécution de la requête: " . $e->getMessage());
				}
				$connection->close();
				
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
			<!-- Formulaire de filtrage -->
			<h3>Filtrer les données</h3>
			
			<form method="post">
				<div class="card">
					<table>
						<thead>
							<tr>
								<th colspan="3">Filtrer par :</th>
								<th colspan="2">Trier par :</th>
								<th>Nb de lignes par feuille:</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>affectation</td>
								<td>Catégorie</td>
								<td>En service</td>
								<td rowspan="2">
									<select name="tri">
										<option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>Identifiant</option>
										<option value="ref" <?= $sort === 'ref' ? 'selected' : '' ?>>Référence</option>
										<option value="affectation_id" <?= $sort === 'affectation_id' ? 'selected' : '' ?>>affectation</option>
										<option value="date_controle" <?= $sort === 'date_controle' ? 'selected' : '' ?>>Date de vérification</option>
										<option value="fabricant" <?= $sort === 'fabricant' ? 'selected' : '' ?>>Fabricant</option>
									</select>
								</td>
								<td colspan="2" rowspan="2">
									<input type="number" name="long" value="<?= $params['long'] ?>">
								</td>
							</tr>
							<tr>
								<td>
									<select name="affectation_id">
										<?= $listeaffectations[0] ?>
									</select>
								</td>
								<td>
									<select name="cat_id">
										<?= $listeCategories[0] ?>
									</select>
								</td>
								<td>
									<select name="est_en_service">
										<option value=<?=$enservice['oui']?>>Oui</option>
										<option value=<?=$enservice['non']?>>Non</option>
										<option value=<?=$enservice['*']?>>Tous</option>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
					<p></p>
					<input class="btn btn-secondary" type="submit" name="choix" value="Filtrer et trier">
					<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
					<input type="hidden" name="nblignes" value="<?= $nblignes ?>" />
				</div>
			</form>
			
			<!-- Affichage des résultats -->
			<?php if ($result && $nblignes > 0): ?>
			<hr>
			<h3>Liste</h3>
			
			<form method="post">
				<div class="card">
					<table>
						<thead>
							<tr>
								<th>#</th>
								<th>Ref</th>
								<th>Libellé</th>
								<th>Fabricant</th>
								<th>Cat</th>
								<th>affectation</th>
								<th>Nb éléments</th>
								<th>Date Vérif</th>
								<th>Date Max</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($result as $key => $value): ?>
							<tr>
								<td><input type="radio" name="id" value="<?= $value['id'] ?>"></td>
								<td><?= htmlspecialchars($value['ref']) ?></td>
								<td><?= htmlspecialchars($value['libelle']) ?></td>
								<td><?= htmlspecialchars($value['fabricant']) ?></td>
								<td><?= htmlspecialchars($value['categorie']) ?></td>
								<td><?= htmlspecialchars($value['affectation']) ?></td>
								<td><?= htmlspecialchars($value['nb_elements']) ?></td>
								<td><?= htmlspecialchars($value['date_controle']) ?></td>
								<td><?= htmlspecialchars($value['date_max']) ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					
					<!-- Pagination -->
					<?php if ($nbpages > 1): ?>
					<div class="pagination">
						<button class="btn btn-secondary" type="submit" name="debut" value="1" <?= $params['debut'] <= 1 ? 'disabled' : '' ?>>Première</button>
						<button class="btn btn-secondary" type="submit" name="debut" value="<?= max(1, $params['debut'] - $params['long']) ?>" <?= $params['debut'] <= 1 ? 'disabled' : '' ?>>Précédente</button>
						<span>Page <?= ceil($params['debut'] / $params['long']) ?> sur <?= $nbpages ?></span>
						<button  class="btn btn-secondary" type="submit" name="debut" value="<?= min($nblignes, $params['debut'] + $params['long']) ?>" <?= $params['debut'] + $params['long'] > $nblignes ? 'disabled' : '' ?>>Suivante</button>
						<button  class="btn btn-secondary" type="submit" name="debut" value="<?= max(1, ($nbpages - 1) * $params['long'] + 1) ?>" <?= $params['debut'] + $params['long'] > $nblignes ? 'disabled' : '' ?>>Dernière</button>
					</div>
					<?php endif; ?>
					
					<p></p>
					<input type="hidden" name="action" value="affichage">  
					<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
					<input type="hidden" name="appel_liste" value="1">			  
					<input type="hidden" name="long" value="<?= $params['long'] ;?>">
					<input type="submit" class="btn btn-primary btn-block" name="submit" value="Afficher la fiche">
				</div>
			</form>
			<?php else: ?>
			<p>Aucune fiche trouvée !</p>
			<?php endif; ?>
			<div id="fin_page" class="card h-100 card-body">
				<form action="liste_affichage.php" method="post" target="_blank">
					<div class="card">
						<table width="100%">
							<tr>
								<td align="left">
									<a href="index.php" ><input type="button" name="retour" value="Retour" class="btn btn-return" /></a>
								</td>
								<td align="right">
									<input type="submit" name="envoyer" value="Fiche à imprimer" class="btn btn-secondary"/>
									<input type="hidden" name="tri" value="<?= $params['tri'] ;?>" />  
									<input type="hidden" name="cat_id" value="<?= $params['cat_id'] ;?>" />  
									<input type="hidden" name="affectation_id" value="<?= $params['affectation_id'] ;?>" />  
									<input type="hidden" name="debut" value="<?= $params['debut'] ;?>" />  
									<input type="hidden" name="long" value="<?= $params['long'] ;?>" />  
									<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
								</td>
							</tr>
						</table>
					</div>
				</form>
			</div>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>