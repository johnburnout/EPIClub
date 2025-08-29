<?php

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . "/includes/fonctions_edition.php";

if (!$isAdmin) {
	header('Location: /');
	exit();
}

if (!$_POST['csrf_token'] || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
	throw new \Exception('Erreur de sécurité: Token CSRF invalide');
}

if (isset($_POST['id'])) {
	header('Location: fiche_affectation.php?id=' . $_POST['id'] . '&action=' . $_POST['action'] . '&retour=liste_affectations.php&csrf_token=' . $csrf_token);
	exit();
};

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

$whereClauses = [];  // Conditions WHERE
$queryParams = [];   // Paramètres pour la requête préparée
$types = '';		 // Types des paramètres (i = integer, s = string)

$where = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);
$allowedSort = ['id', 'libelle'];
$sort = in_array($params['tri'], $allowedSort) ? $params['tri'] : 'libelle';

$sql = "SELECT id, libelle FROM affectation $where ORDER BY $sort";
$stmt = $db->prepare($sql);
if (!empty($queryParams)) {
	$stmt->bind_param($types, ...$queryParams);
}
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="fr">

<head>
	<?php include __DIR__ . '/includes/head.php'; ?>
</head>

<body>
	<header style="text-align: right; padding: 10px;">
		<?php include __DIR__ . '/includes/bandeau.php'; ?>
	</header>
	<main>
		<?php include __DIR__ . '/includes/en_tete.php'; ?>
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
								<option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>Id de l'affectation</option>
								<option value="libelle" <?= $sort === 'libelle' ? 'selected' : '' ?>>Nom de l'affectation</option>
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
							<th>affectation</th>
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

				<p">
					<input type="hidden" name="action" value="maj">
					<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
					<input type="hidden" name="retour" value="fiche_affectation.php">
					<input type="submit" class="btn btn-primary btn-block" name="submit" value="Afficher le affectation">
					</p>
			</form>
			<form method="post" action="fiche_affectation.php">
				<input type="hidden" name="action" value="creation" />
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
				<input type="hidden" name="id" value="0" />
				<table>
					<tr>
						<td>
							<a href="index.php">
								<input type="button" class="btn return-btn btn-block" value="Revenir à l'accueil">
							</a>
						</td>
						<td>
							<input type="submit" name="creation" value="Nouveau affectation" class="btn btn-primary" />
						</td>
					</tr>
				</table>
			</form>
		<?php else: ?>
			<p>Aucun affectation trouvé !</p>
		<?php endif; ?>
	</main>
	<footer>
		<?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
	</footer>
</body>

</html>