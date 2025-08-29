<?php

require __DIR__ . '/app/bootstrap.php';

if (!$isLoggedIn) {
	header('Location: /');
	exit();
}

if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
	throw new Exception('Erreur de sécurité: Token CSRF invalide');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$avis = '';
$retour = '/';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' or !$isLoggedIn or ($id != $_SESSION['controle_en_cours'])) {
	die('Erreur : Permissions insuffisantes');
}

if ($id === 0) {
	$stmt = $db->prepare("SELECT MAX(id) AS max_id FROM controle");
	$stmt->execute();
	$result = $stmt->get_result();
	$idmax = $result->fetch_assoc();
	$id = (int)$idmax['max_id'];
}

$stmt1 = $db->prepare("DELETE FROM controle WHERE id = ?");
$stmt1->bind_param("i", $id);
$stmt1->execute();

if ($db->affected_rows > 0) {
	$avis = "Le contrôle a été supprimé avec succès";
	$_SESSION['controle_en_cours'] = 0;
	$_SESSION['epi_controles'] = '';

	$sql = "UPDATE utilisateur SET controle_en_cours = 0 WHERE username = ?";
	$stmt2 = $connection->prepare($sql);
	$stmt2->bind_param(
		"s",
		$utilisateur
	);
	$stmt2->execute();
} else {
	$avis = "Aucun enregistrement trouvé à supprimer";
}

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
		<?php if ($avis): ?>
			<div class="alert <?= strpos($avis, 'Attention') !== false ? 'alert-warning' : 'alert-info' ?>">
				<?= htmlspecialchars($avis) ?>
			</div>
		<?php endif; ?>
		<div>
			<p>
			<form action="<?= $retour ?>">
				<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
				<input type="submit" class="btn return-btn" name="retour" value="Retour">
			</form>
			</p>
		</div>
	</main>
	<footer>
		<?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
	</footer>
</body>

</html>