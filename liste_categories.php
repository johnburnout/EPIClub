<?php

	require __DIR__ . '/config.php';		  // Fichier de configuration principal
	require __DIR__.'/includes/communs.php';  // Fonctions communes
	
	// Vérification des permissions
	// Validation CSRF
	if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
		throw new Exception('Erreur de sécurité: Token CSRF invalide');
	}

	if (!$isAdmin) {
		header('Location: index.php?');
		exit();
	}
	
	if (isset($_POST['id'])) {
		header('Location: fiche_categorie.php?id='.$_POST['id'].'&action='.$_POST['action'].'&retour=liste_categories.php&csrf_token='.$csrf_token);
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
		'id' => 1,		   // Filtre catégorie (0 = tous)
		'tri' => 'libelle',		   // Champ de tri par défaut
	];
	
	// Traitement des paramètres de requête
	$params = [];
	foreach ($defaults as $key => $default) {
		if (isset($_POST[$key])) {
			// Nettoie l'entrée selon son type (int ou string)
			$params[$key] = sanitizeInput($_POST[$key], is_numeric($default) ? 'int' : 'string');
		} else {
				// Utilise la valeur par défaut si le paramètre n'est pas fourni
				$params[$key] = $default;
		}
	}
	
	// ##############################################
	// CONSTRUCTION DE LA REQUÊTE SQL SÉCURISÉE
	// ##############################################
	
	$whereClauses = [];  // Conditions WHERE
	$queryParams = [];   // Paramètres pour la requête préparée
	$types = '';		 // Types des paramètres (i = integer, s = string)
	
	// Combinaison des conditions WHERE
	$where = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);
	
	// Validation du champ de tri (whitelist)
	$allowedSort = ['id', 'libelle'];
	$sort = in_array($params['tri'], $allowedSort) ? $params['tri'] : 'libelle';
	
	// Exécution de la requête principale
	try {
		$sql = "SELECT id, libelle
		FROM categorie $where ORDER BY $sort";
		
		$stmt = $connection->prepare($sql);
		
		// Liaison des paramètres si nécessaire
		if (!empty($queryParams)) {
			$stmt->bind_param($types, ...$queryParams);
		}
		
		// Exécution et récupération des résultats
		$stmt->execute();
		$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
				<table>
					<thead>
						<tr>
							<th colspan="1">Trier par :</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td rowspan="1">
								<select name="tri">
									<option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>Identifiant</option>
									<option value="libelle" <?= $sort === 'libelle' ? 'selected' : '' ?>>Nom de la catégorie</option>
								</select>
							</td>:
						</tr>
					</tbody>
				</table>
				<p></p>
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
				<input class="btn btn-secondary" type="submit" name="choix" value="Filtrer et trier">
			</form>
			
			<!-- Affichage des résultats -->
			<?php if ($result): ?>
			<hr>
			<h3>Liste</h3>
			
			<form method="post">
				<table>
					<thead>
						<tr>
							<th>#</th>
							<th>Catégorie</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($result as $key => $value): ?>
						<tr>
							<td><input type="radio" name="id" value="<?= $value['id'] ?>"></td>
							<td><?= htmlspecialchars($value['libelle'] ?? '') ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				
				<p align="right">
					<input type="hidden" name="action" value="maj">
					<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
					<input type="hidden" name="retour" value="fiche_categorie.php">
					<input type="submit" class="btn btn-primary btn-block" name="submit" value="Afficher la catégorie">
				</p>
			</form>
			<form method="post" action="fiche_categorie.php">
				<input type="hidden" name="action" value="creation" />
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
				<input type="hidden" name="id" value="0" />
				<table>
					<tr>
						<td align="left">
							<a href="index.php">
								<input type="button" class="btn return-btn btn-block"value="Revenir à l'accueil">
							</a>
						</td>
						<td align="left">
							<input type="submit" name="creation" value="Nouvelle catégorie" class="btn btn-primary"/>
						</td>
					</tr>
				</table>
			</form>
			<?php else: ?>
			<p>Aucune catégorie trouvée !</p>
		<?php endif; ?>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>