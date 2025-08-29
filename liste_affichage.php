<?php

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . "/includes/fonctions_edition.php";

if (!$isLoggedIn) {
	header('Location: /');
	exit();
}

if (!$_POST['csrf_token'] || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
	throw new \Exception('Erreur de sécurité: Token CSRF invalide');
}

$defaults = [
	'debut' => (int)$_POST['debut'] ?? 1,			// Première ligne à afficher
	'long' => (int)$_POST['long'] ?? 10,			// Nombre de lignes par page
	'affectation_id' => (int)$_POST['affectation_id'] ?? 0,		  // Filtre affectation (0 = tous)
	'cat_id' => (int)$_POST['cat_id'] ?? 0,		   // Filtre catégorie (0 = tous)
	'tri' => (string)$_POST['tri'] ?? 'id',		   // Champ de tri par défaut
	'est_en_service' => 1  // Filtre "en service" par défaut (1 = oui)
];

$params = [];
foreach ($defaults as $key => $default) {
	if (isset($_POST[$key])) {
		$params[$key] = sanitizeInput($_POST[$key], is_numeric($default) ? 'int' : 'string');
	} else {
		$params[$key] = $default;
	}
}

$params['debut'] = $_SESSION['debut'] ?? $defaults['debut'];
$params['long'] = $_SESSION['long'] ?? $defaults['long'];
$params['nblignes'] = $_SESSION['nblignes'] ?? $defaults['nblignes'];

$whereClauses = [];  // Conditions WHERE
$queryParams = [];   // Paramètres pour la requête préparée
$types = '';		 // Types des paramètres (i = integer, s = string)

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

$whereClauses[] = "en_service = 1";
$types .= 'ii';
$queryParams[] = (int)$params['debut'] - 1;
$queryParams[] = (int)$params['long'];

$where = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

$allowedSort = ['id', 'ref', 'affectation_id', 'date_controle', 'fabricant'];
$sort = in_array($params['tri'], $allowedSort) ? $params['tri'] : 'id';

$sql = "SELECT id, ref, libelle, fabricant, categorie, categorie_id, 
		affectation, affectation_id, nb_elements, date_controle, date_max
		FROM liste $where ORDER BY $sort LIMIT ?, ?";
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
	<main>
		<?php if ($result): ?>
			<h3><?= $site_name ?></h3>
			<table width="100%" style="table-layout: fixed; border-collapse: collapse;">
				<tbody>
					<?php foreach ($result as $key => $value): ?>
						<tr style="border-top: 1px solid #000000;">
							<td colspan="2">Référence : <?= htmlspecialchars($value['ref']) ?></td>
							<td rowspan="5" style="width: 150px; padding: 5px; vertical-align: middle; text-align: center;">
								<?php if (file_exists(__DIR__ . '/utilisateur/qrcodes/qrcode' . $value['id'] . '_300.png')): ?>
									<img src="utilisateur/qrcodes/qrcode<?= htmlspecialchars($value['id']) ?>_300.png"
										style="max-width: 100%; max-height: 150px; display: inline-block;"
										alt="QR Code <?= htmlspecialchars($value['ref']) ?>"
										title="QR Code pour <?= htmlspecialchars($value['libelle']) ?>">
								<?php else: ?>
									<div style="width: 150px; height: 150px; background: #f5f5f5; display: flex; align-items: center; justify-content: center;">
										QR Code manquant
									</div>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td style="vertical-align: middle;"><b><?= htmlspecialchars($value['libelle']) ?></b></td>
							<td style="vertical-align: middle;">affectation : <?= htmlspecialchars($value['affectation']) ?></td>
						</tr>
						<tr>
							<td style="vertical-align: middle;"><?= htmlspecialchars($value['fabricant']) ?></td>
							<td style="vertical-align: middle;"><?= htmlspecialchars($value['categorie']) ?></td>
						</tr>
						<tr>
							<td style="vertical-align: middle;">Nombre d'éléments :</td>
							<td style="vertical-align: middle;"><?= htmlspecialchars($value['nb_elements']) ?></td>
						</tr>
						<tr>
							<td colspan="2" style="vertical-align: middle;">Date max : <?= htmlspecialchars($value['date_max']) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else: ?>
			<p>Aucune fiche trouvée !</p>
		<?php endif; ?>
	</main>
	<footer>
		<?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
	</footer>
</body>

</html>