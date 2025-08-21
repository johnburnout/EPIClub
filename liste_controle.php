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
	
	if (isset($_POST['id'])) {
		header('Location: fiche_controle.php?id='.$_POST['id'].'&action=controler&csrf_token='.$csrf_token);
		exit();
	};
	
	// #############################
	// Initialisation variables
	// #############################
	
	$retour = 'index.php';
	
	// #############################
	// Connexion sécurisée à la base de données
	// #############################
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	try {
		$connection = new mysqli($host, $username, $password, $dbname);
		$connection->set_charset("utf8mb4");
	} catch (mysqli_sql_exception $e) {
		error_log("[" . date('Y-m-d H:i:s') . "] Erreur DB: " . $e->getMessage());
		die("Erreur de connexion à la base de données. Veuillez réessayer plus tard.");
	}
	
	// #############################
	// Gestion des contrôles en cours
	// #############################
	$controle_id = htmlspecialchars($_SESSION['controle_en_cours'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$controleOuvert = ($controle_id > 0);
	if (!$controleOuvert && $isLoggedIn) {
		$controle = lecture_controle(0, $utilisateur);
		$controleOuvert = $controle['success'] ?? false;
		$controle_id = $controleOuvert ? (int)($controle['controle_id'] ?? 0) : null;
	}
	
	// #############################
	// Gestion de la pagination avec validation
	// #############################
	$defaults = [
		'debut' => 1,
		'long' => 20,
		'nblignes' => 20,
		'id' => 1,
		'lieu_id' => 0,
		'cat_id' => 0,
		'tri' => 'id'
	];
	
	// Traitement des paramètres avec validation renforcée
	$params = [];
	foreach ($defaults as $key => $default) {
		$input = $_POST[$key] ?? $_SESSION[$key] ?? $default;
			$params[$key] = sanitizeInput($input, is_numeric($default) ? 'int' : 'string');
			}
				$params['verification_id'] = intval($_SESSION['controle_en_cours']);
				// Gestion des cookies sécurisés
				if ($_SERVER['REQUEST_METHOD'] === 'POST') {
					$_SESSION['debut'] = $params['debut'];
					$_SESSION['long'] = $params['long'];
					$_SESSION['nblignes'] = $params['nblignes'];
				}
				
				// #############################
				// Création des listes d'options sécurisées
				// #############################
				$current_lieu_id = $params['lieu_id'];
				$current_categorie_id = $params['cat_id'];
				
				$listeLieux = liste_options(['libelles' => 'lieu', 'id' => $current_lieu_id]);
				$listeCategories = liste_options(['libelles' => 'categorie', 'id' => $current_categorie_id]);
				
				// ###################################
				// Construction de la requête principale avec protection
				// ###################################
				$whereClauses = ["en_service = 1"];
				$queryParams = [];
				$types = '';
				
				if ($params['lieu_id'] > 0) {
					$whereClauses[] = "lieu_id = ?";
					$queryParams[] = $params['lieu_id'];
					$types .= 'i';
				}
				
				if ($params['cat_id'] > 0) {
					$whereClauses[] = "id = ?";
					$queryParams[] = $params['cat_id'];
					$types .= 'i';
				}
				
				if ($params['verification_id'] > 0) {
					$whereClauses[] = "verification_id != ?";
					$queryParams[] = $params['verification_id'];
					$types .= 'i';
				}
				
				
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
				
				//**************
				
				$types .= 'ii';
				$queryParams[] = (int)$params['debut']-1;
				$queryParams[] = (int)$params['long'];
				
				$where = implode(' AND ', $whereClauses);
				// Validation du champ de tri
				$allowedSort = ['id', 'ref', 'lieu_id', 'date_verification', 'fabricant'];
				$sort = in_array($params['tri'], $allowedSort) ? $params['tri'] : 'id';
				// ###########################
				// Recherche dans la base avec pagination sécurisée
				// ###########################
				try {
					// Requête SQL avec un seul LIMIT
					$sql = "SELECT id, ref, libelle, fabricant, categorie, categorie_id, 
					lieu, lieu_id, nb_elements, date_verification, date_max
					FROM liste 
					WHERE $where 
					ORDER BY $sort 
					LIMIT ?, ?";
					
					$stmt = $connection->prepare($sql);
					
					$stmt->bind_param($types, ...$queryParams);
					
					$stmt->execute();
					$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
				} catch (mysqli_sql_exception $e) {
					error_log("[" . date('Y-m-d H:i:s') . "] Erreur DB: " . $e->getMessage());
					die("Une erreur est survenue lors de la récupération des données. Veuillez réessayer.");
				}	$connection->close();
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
			<h3>Filtrer les données</h3>
			
			<form method="post">
				<div class="card">
					<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
					<table>
						<thead>
							<tr>
								<th colspan="2">Filtrer par :</th>
								<th colspan="2">Trier par :</th>
								<th>Nb de lignes par page:</th>					
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>Lieu</td>
								<td>Catégorie</td>
								<td rowspan="2">
									<select name="tri">
										<option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>Identifiant</option>
										<option value="ref" <?= $sort === 'ref' ? 'selected' : '' ?>>Référence</option>
										<option value="lieu_id" <?= $sort === 'lieu_id' ? 'selected' : '' ?>>Lieu</option>
										<option value="date_verification" <?= $sort === 'date_verification' ? 'selected' : '' ?>>Date de vérification</option>
										<option value="fabricant" <?= $sort === 'fabricant' ? 'selected' : '' ?>>Fabricant</option>
									</select>
								</td>
								<td colspan="2" rowspan="2">
									<input type="number" name="long" min="5" max="100" step="5" value="<?= $params['long'] ?>">
								</td>
							</tr>
							<tr>
								<td>
									<select name="lieu_id">
										<?= $listeLieux[0] ?? '' ?>
									</select>
								</td>
								<td>
									<select name="cat_id">
										<?= $listeCategories[0] ?? '' ?>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
					<p>
						<input class="btn btn-secondary" type="submit" name="choix" value="Filtrer et trier">
					</p>
				</div>
			</form>
			
			<?php if ($result && $nblignes > 0): ?>
			<hr>
			<h3>Liste des EPI (<?= $nblignes ?> résultats)</h3>
			
			<form method="post">				
				<div class="card">
					<table>
						<thead>
							<tr>
								<th>#</th>
								<th>Référence</th>
								<th>Libellé</th>
								<th>Fabricant</th>
								<th>Catégorie</th>
								<th>Lieu</th>
								<th>Quantité</th>
								<th>Dernière vérif</th>
								<th>Prochaine vérif</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($result as $row): ?>
							<tr>
								<td><input type="radio" name="id" value="<?= (int)$row['id'] ?>"></td>
								<td><?= htmlspecialchars($row['ref'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= htmlspecialchars($row['libelle'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= htmlspecialchars($row['fabricant'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= htmlspecialchars($row['categorie'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= htmlspecialchars($row['lieu'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= (int)$row['nb_elements'] ?></td>
								<td><?= htmlspecialchars($row['date_verification'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= htmlspecialchars($row['date_max'], ENT_QUOTES, 'UTF-8') ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="card">
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
					
					<p>			   
						<input type="hidden" name="action" value="affichage">			  
						<input type="hidden" name="long" value="<?= $params['long'] ;?>"> 
						<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
						<input class="btn btn-primary" type="submit" name="submit" value="Contrôler">
					</p>
				</div>
			</form>
			<?php else: ?>
			<div class="alert alert-error">
				Aucune fiche trouvée avec les critères sélectionnés.
			</div>
			<?php endif; ?>
			
			<!--		<?php //if ($controleOuvert): ?> --> 
			<div style="border-top: 1px solid var(--border-color);">
				<form method="post" action="controle_terminer.php" onsubmit="return confirm('Êtes-vous sûr de vouloir terminer ce contrôle ?');">
					<a href="<?= $retour = 'index.php'; ?>">
						<input type="button" value="Retour à l'accueil" class="btn return-btn">
					</a>
					<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
					<input type="hidden" name="controle_id" value="<?= (int)$controle_id ?>">
					<input type="hidden" name="retour" value="index.php">				
					<input type="hidden" name="action" value="affichage">			  
					<input type="hidden" name="long" value="<?= $params['long'] ;?>">
					<input type="submit" name="terminer" value="Terminer le contrôle en cours"  class="btn btn-primary">
				</form>
			</div>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>