<?php

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . "/includes/bdd/liste_options.php";
require __DIR__ . "/includes/bdd/lecture_controle.php";
require __DIR__ . "/includes/fonctions_edition.php";

if (!$isLoggedIn) {
	header('Location: /');
	exit();
}

if (!$_POST['csrf_token'] || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
	throw new \Exception('Erreur de sécurité: Token CSRF invalide');
}

if (isset($_POST['id'])) {
	header('Location: controle_epi.php?id=' . $_POST['id'] . '&action=controler&csrf_token=' . $csrf_token);
	exit();
};

$retour = '/';

$controle_id = htmlspecialchars($_SESSION['controle_en_cours'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$controleOuvert = ($controle_id > 0);
if (!$controleOuvert && $isLoggedIn) {
	$controle = lecture_controle(0, $utilisateur);
	$controleOuvert = $controle['success'] ?? false;
	$controle_id = $controleOuvert ? (int)($controle['controle_id'] ?? 0) : null;
}

$defaults = [
	'debut' => 1,
	'long' => 20,
	'nblignes' => 20,
	'id' => 1,
	'affectation_id' => 0,
	'cat_id' => 0,
	'tri' => 'id'
];

$params = [];
foreach ($defaults as $key => $default) {
	$input = $_POST[$key] ?? $_SESSION[$key] ?? $default;
	$params[$key] = sanitizeInput($input, is_numeric($default) ? 'int' : 'string');
}
$params['controle_id'] = intval($_SESSION['controle_en_cours']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$_SESSION['debut'] = $params['debut'];
	$_SESSION['long'] = $params['long'];
	$_SESSION['nblignes'] = $params['nblignes'];
}

$current_affectation_id = $params['affectation_id'];
$current_categorie_id = $params['cat_id'];
$listeaffectations = liste_options(['libelles' => 'affectation', 'id' => $current_affectation_id]);
$listeCategories = liste_options(['libelles' => 'categorie', 'id' => $current_categorie_id]);

$whereClauses = ["en_service = 1"];
$queryParams = [];
$types = '';

if ($params['affectation_id'] > 0) {
	$whereClauses[] = "affectation_id = ?";
	$queryParams[] = $params['affectation_id'];
	$types .= 'i';
}

if ($params['cat_id'] > 0) {
	$whereClauses[] = "id = ?";
	$queryParams[] = $params['cat_id'];
	$types .= 'i';
}

if ($params['controle_id'] > 0) {
	$whereClauses[] = "controle_id != ?";
	$queryParams[] = $params['controle_id'];
	$types .= 'i';
}

$where = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

$countSql = "SELECT COUNT(*) AS total FROM liste $where";
$countStmt = $db->prepare($countSql);

if (!empty($queryParams)) {
	$countStmt->bind_param($types, ...$queryParams);
}

$countStmt->execute();
$totalCount = $countStmt->get_result()->fetch_assoc()['total'];
$nblignes = (int)$totalCount;
$nbpages = ceil($nblignes / max(1, $params['long']));

$types .= 'ii';
$queryParams[] = (int)$params['debut'] - 1;
$queryParams[] = (int)$params['long'];

$where = implode(' AND ', $whereClauses);

$allowedSort = ['id', 'ref', 'affectation_id', 'date_controle', 'fabricant'];
$sort = in_array($params['tri'], $allowedSort) ? $params['tri'] : 'id';

$sql = "SELECT id, ref, libelle, fabricant, categorie, categorie_id, 
		affectation, affectation_id, nb_elements, date_controle, date_max
		FROM liste 
		WHERE $where 
		ORDER BY $sort 
		LIMIT ?, ?";
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$queryParams);
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
							<td>affectation</td>
							<td>Catégorie</td>
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
								<input type="number" name="long" min="5" max="100" step="5" value="<?= $params['long'] ?>">
							</td>
						</tr>
						<tr>
							<td>
								<select name="affectation_id">
									<?= $listeaffectations[0] ?? '' ?>
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
								<th>affectation</th>
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
									<td><?= htmlspecialchars($row['affectation'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><?= (int)$row['nb_elements'] ?></td>
									<td><?= htmlspecialchars($row['date_controle'], ENT_QUOTES, 'UTF-8') ?></td>
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
							<button class="btn btn-secondary" type="submit" name="debut" value="<?= min($nblignes, $params['debut'] + $params['long']) ?>" <?= $params['debut'] + $params['long'] > $nblignes ? 'disabled' : '' ?>>Suivante</button>
							<button class="btn btn-secondary" type="submit" name="debut" value="<?= max(1, ($nbpages - 1) * $params['long'] + 1) ?>" <?= $params['debut'] + $params['long'] > $nblignes ? 'disabled' : '' ?>>Dernière</button>
						</div>
					<?php endif; ?>

					<p>
						<input type="hidden" name="action" value="affichage">
						<input type="hidden" name="long" value="<?= $params['long']; ?>">
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

		<!--		<?php //if ($controleOuvert): 
					?> -->
		<div style="border-top: 1px solid var(--border-color);">
			<form method="post" action="controle_terminer.php" onsubmit="return confirm('Êtes-vous sûr de vouloir terminer ce contrôle ?');">
				<a href="<?= $retour = 'index.php'; ?>">
					<input type="button" value="Retour à l'accueil" class="btn return-btn">
				</a>
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="hidden" name="controle_id" value="<?= (int)$controle_id ?>">
				<input type="hidden" name="retour" value="index.php">
				<input type="hidden" name="action" value="affichage">
				<input type="hidden" name="long" value="<?= $params['long']; ?>">
				<input type="submit" name="terminer" value="Terminer le contrôle en cours" class="btn btn-primary">
			</form>
		</div>
	</main>
	<footer>
		<?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
	</footer>
</body>

</html>