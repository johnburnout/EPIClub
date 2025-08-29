<?php

require __DIR__ . '/app/bootstrap.php';

if (!$isAdmin) {
	header('Location: /');
	exit();
}

if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
	throw new \Exception('Erreur de sécurité: Token CSRF invalide');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$id_0 = $id;
$retour = isset($_GET['retour']) ? $_GET['retour'] : (isset($_POST['retour']) ? $_POST['retour'] : '/');
$avis = '';

	if ($id === 0) {
		$stmt = $db->prepare("SELECT MAX(id) AS max_id FROM acquisition");
		$stmt->execute();
		$result = $stmt->get_result();
		$idmax = $result->fetch_assoc();
		$id = (int)$idmax['max_id'];
	}

	$stmt1 = $db->prepare("DELETE FROM acquisition WHERE id = ?");
	$stmt1->bind_param("i", $id);
	$stmt1->execute();

	$stmt2 = $db->prepare("DELETE FROM matos WHERE acquisition_id = ?");
	$stmt2->bind_param("i", $id);
	$stmt2->execute();

	if ($db->affected_rows > 0) {
		$avis = "l'acquisition a été supprimée avec succès";
		$_SESSION['acquisition_en_saisie'] = 0;

		$sql = "UPDATE utilisateur SET acquisition_en_saisie = 0 WHERE username = ?";
		$stmt2 = $db->prepare($sql);
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
			<form action=<?= $retour ?>>
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