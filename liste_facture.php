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

	if (!$isLoggedIn) {
		header('Location: index.php?');
		exit();
	}
	
	
	// #############################
	// Initialisation variables
	// #############################
	
	$retour = isset($_GET['retour']) ? $_GET['retour'] : (isset($_POST['retour']) ? $_POST['retour'] : 'index.php');
			
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
	// Gestion des factures en saisie
	// #############################
	$facture_id = intval($_SESSION['facture_en_saisie']);
	$factureOuvert = ($facture_id > 0);
	if (!$factureOuvert && $isLoggedIn) {
		$facture = lecture_facture($_SESSION['facture_en_saisie'], $utilisateur);
		$factureOuvert = $facture['success'] ?? false;
		$facture_id = $factureOuvert ? (int)($facture['id'] ?? 0) : null;
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
	$params = ['lieu_id' => 0, 'cat_id'=> 0];
	foreach ($defaults as $key => $default) {
		$input = $_POST[$key] ?? $_SESSION[$key] ?? $default;
		$params[$key] = sanitizeInput($input, is_numeric($default) ? 'int' : 'string');
	}
	$params['facture_id'] = intval($_SESSION['facture_en_saisie']);
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
	$listeLieux[0] = "<option value='*'>Tous</option>".$listeLieux[0];
	$listeCategories = liste_options(['libelles' => 'categorie', 'id' => $current_categorie_id]);
	$listeCategories[0] = "<option value='*'>Toutes</option>".$listeCategories[0];
	
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
		$whereClauses[] = "categorie_id = ?";
		$queryParams[] = $params['cat_id'];
		$types .= 'i';
	}
	
	if ($params['facture_id'] > 0) {
		$whereClauses[] = "facture_id = ?";
		$queryParams[] = $params['facture_id'];
		$types .= 'i';
	}
	
	$where = implode(' AND ', $whereClauses);
	// Validation du champ de tri
	$allowedSort = ['id', 'ref', 'lieu_id', 'date_facture', 'fabricant'];
	$sort = in_array($params['tri'], $allowedSort) ? $params['tri'] : 'id';
	
	// ###########################
	// Recherche dans la base avec pagination sécurisée
	// ###########################
	try {
		// Requête pour le comptage total
		$countSql = "SELECT COUNT(*) AS total FROM liste WHERE $where";
		$countStmt = $connection->prepare($countSql);
		
		if (!empty($queryParams)) {
			$countStmt->bind_param($types, ...$queryParams);
		}
		
		$countStmt->execute();
		$totalCount = $countStmt->get_result()->fetch_assoc()['total'];
		$nblignes = (int)$totalCount;
		$nbpages = ceil($nblignes / max(1, $params['long']));
		
		// Requête pour les données paginées
		$sql = "SELECT id, ref, libelle, fabricant, categorie, categorie_id, 
		lieu, lieu_id, nb_elements, date_verification, date_max
		FROM liste 
		WHERE $where 
		ORDER BY $sort 
		LIMIT ? OFFSET ?";
		
		$stmt = $connection->prepare($sql);
		
		$limit = max(1, min($params['long'], 100)); // Limite à 100 max par page
		$offset = max(0, ($params['debut'] - 1) * $limit);
		
		if (!empty($queryParams)) {
			$stmt->bind_param($types.'ii', ...array_merge($queryParams, [$limit, $offset]));
		} else {
			$stmt->bind_param('ii', $limit, $offset);
		}
		
		$stmt->execute();
		$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
	} catch (mysqli_sql_exception $e) {
		error_log("[" . date('Y-m-d H:i:s') . "] Erreur DB: " . $e->getMessage());
		die("Une erreur est survenue lors de la récupération des données. Veuillez réessayer.". $e->getMessage());
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
			<?php if ($isLoggedIn): ?>
			<h3>Filtrer les données</h3>
			
			<form method="post">
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<table>
					<thead>
						<tr>
							<th colspan="2">Filtrer par :</th>
							<th colspan="2">Trier par :</th>
							<th>Nb de lignes par page:</th>
							<th>Page :</th>
						</tr>
						<tr>
							<td>Lieu</td>
							<td>Catégorie</td>
							<td rowspan="2">
								<select name="tri">
									<option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>Identifiant</option>
									<option value="ref" <?= $sort === 'ref' ? 'selected' : '' ?>>Référence</option>
									<option value="lieu_id" <?= $sort === 'lieu_id' ? 'selected' : '' ?>>Lieu</option>
									<option value="date_facture" <?= $sort === 'date_facture' ? 'selected' : '' ?>>Date de vérification</option>
									<option value="fabricant" <?= $sort === 'fabricant' ? 'selected' : '' ?>>Fabricant</option>
								</select>
							</td>
							<td colspan="2" rowspan="2">
								<input type="number" name="long" min="5" max="100" step="5" value="<?= $params['long'] ?>">
							</td>
							<td rowspan="2">
								<input type="number" name="debut" min="1" max="<?= max(1,$nbpages) ?>" value="<?= ceil($params['debut'] / $params['long']) ?>">
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
					</thead>
				</table>
				<p>
					<input type="submit" class="btn btn-secondary" name="choix" value="Filtrer et trier">
				</p>
			</form>
			
			<?php if ($result && $nblignes > 0): ?>
			<hr>
			<h3>Liste des EPI (<?= $nblignes ?> résultats)</h3>
			
			<form method="get" action="fiche_facture.php">
				<table>
					<thead>
						<tr>
							<th>Référence</th>
							<th>Libellé</th>
							<th>Fabricant</th>
							<th>Catégorie</th>
							<th>Lieu</th>
							<th>Quantité</th>
							<th>Controlé le</th>
							<th>Date max</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($result as $row): ?>
						<tr>
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
				
				<!-- Pagination -->
				<?php if ($nbpages > 1): ?>
				<div class="pagination">
					<button type="submit" class="btn btn-secondary" name="debut" value="1" <?= $params['debut'] <= 1 ? 'disabled' : '' ?>>Première</button>
					<button type="submit" class="btn btn-secondary" name="debut" value="<?= max(1, $params['debut'] - $params['long']) ?>" <?= $params['debut'] <= 1 ? 'disabled' : '' ?>>Précédente</button>
					<span>Page <?= ceil($params['debut'] / $params['long']) ?> sur <?= $nbpages ?></span>
					<button type="submit" class="btn btn-secondary" name="debut" value="<?= min($nblignes, $params['debut'] + $params['long']) ?>" <?= $params['debut'] + $params['long'] > $nblignes ? 'disabled' : '' ?>>Suivante</button>
					<button type="submit" class="btn btn-secondary" name="debut" value="<?= max(1, ($nbpages - 1) * $params['long'] + 1) ?>" <?= $params['debut'] + $params['long'] > $nblignes ? 'disabled' : '' ?>>Dernière</button>
				</div>
				<?php endif; ?>
			</form>
			<?php else: ?>
			<div class="alert alert-error">
				Aucune fiche trouvée avec les critères sélectionnés.
			</div>
			<?php endif; ?>
			
			<!--		<?php //if ($factureOuvert): ?> -->
			<div style="border-top: 1px solid var(--border-color);">
				<form method="post" action="fiche_creation.php">
					<p>
						<a href="index.php">
							<input type="button" value="Retour à l'accueil" class="btn btn-primary">
						</a>
						<input type="submit" value="Créer une fiche EPI" class="btn btn-primary">
						<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
						<input type="hidden" name="facture_id" value="<?= (int)$facture_id ?>">			  
						<input type="hidden" name="retour" value="liste_facture.php">
						<input type="hidden" name="action" value="creation">
					</p>
				</form>
				<form method="post" action="facture_terminer.php" onsubmit="return confirmTerminerFacture()">
				    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
				    <input type="hidden" name="facture_id" value="<?= htmlspecialchars($facture_id ?? '', ENT_QUOTES, 'UTF-8') ?>">
				    <input type="hidden" name="reference" value="<?= htmlspecialchars($result['ref'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
				    
				    <input type="submit" name="terminer" value="Terminer la saisie de la facture" class="btn btn-primary">
				</form>

<script>
function confirmTerminerFacture() {
    return confirm('Êtes-vous sûr de vouloir clôturer cette facture ?\n\nRéférence: <?= addslashes($result['ref'] ?? '') ?>');
}
</script>
			</div>
			<!--		<?php //endif; ?> -->
			<?php else: ?>
			<div class="alert alert-error">
				Vous devez être connecté pour accéder à cette fonctionnalité.
				<a href="login.php" class="btn-primary">Se connecter</a>
			</div>
			<?php endif; ?>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>