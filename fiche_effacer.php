<?php

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . "/includes/fonctions_fichiers.php";

if (!$isAdmin) {
	header('Location: /');
	exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$id_0 = $id;
$avis = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	if (!$_POST['csrf_token'] || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
		throw new \Exception('Erreur de sécurité: Token CSRF invalide');
	}

	if ($id === 0) {
		$stmt = $db->prepare("SELECT MAX(id) AS max_id FROM matos");
		$stmt->execute();
		$result = $stmt->get_result();
		$idmax = $result->fetch_assoc();
		$id = (int)$idmax['max_id'];
	}

	$stmt = $db->prepare("SELECT reference FROM matos WHERE id = ?");
	$stmt->bind_param("i", $id);
	$stmt->execute();
	$result = $stmt->get_result();
	$reference = $result->fetch_assoc()['reference'];

	$stmt = $db->prepare("DELETE FROM matos WHERE id = ?");
	$stmt->bind_param("i", $id);
	$stmt->execute();

	if ($db->affected_rows > 0) {
		$avis = "La fiche a été supprimée avec succès";
		// Journalisation
		$journalmat = __DIR__ . '/utilisateur/enregistrements/journalmat' . $reference . '.txt';
		$journal = __DIR__ . '/utilisateur/enregistrements/journal' . date('Y') . '.txt';
		$ajoutjournal = date('Y/m/d') . ' ' . $utilisateur . ' - ' . 'Suppression de la fiche';

		// Vérification des chemins avant écriture
		$allowedPath = __DIR__ . '/utilisateur/enregistrements/';
		if (strpos($journalmat, $allowedPath) === 0 && strpos($journal, $allowedPath) === 0) {
			try {
				fichier_ecrire(['chemin' => $journalmat, 'texte' => $ajoutjournal]);
				fichier_ecrire(['chemin' => $journal, 'texte' => $ajoutjournal]);
				//file_put_contents($journalmat, $ajoutjournal.PHP_EOL, FILE_APPEND | LOCK_EX);
				//file_put_contents($journal, $reference.PHP_EOL.$ajoutjournal.PHP_EOL, FILE_APPEND | LOCK_EX);
			} catch (\Exception $e) {
				error_log("Erreur journalisation: " . $e->getMessage());
			}
		}
	} else {
		$avis = "Aucune fiche trouvée à supprimer";
	}
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
			<div class="alert <?= strpos($avis, 'Attention') !== false ? 'alert-warning' : 'alert-success' ?>">
				<?= htmlspecialchars($avis, ENT_QUOTES, 'UTF-8') ?>
			</div>
		<?php endif; ?>
		<div>
			<p>
			<form method="get" action=<?= $_GET['retour'] ?>>
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