<?php

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . "/includes/fonctions_edition.php";

if (!$isAdmin) {
	header('Location: /');
	exit();
}



$id = isset($_POST['acquisition_id']) ? (int)$_POST['acquisition_id'] : 0;
$retour = "index.php";
$abandon = filter_var($_POST['abandon'] ?? '', FILTER_SANITIZE_URL);
$bouton = '';
$avis = 'Contrôle cloturé';
$reference = isset($_POST['reference']) ? htmlspecialchars($_POST['reference'], ENT_QUOTES, 'UTF-8') : '';
$remarque = isset($_POST['remarque']) ? htmlspecialchars($_POST['remarque'], ENT_QUOTES, 'UTF-8') : '';

$journalacquisition = __DIR__ . '/utilisateur/enregistrements/journalacquisition_' . $id . '.txt';
$journal = __DIR__ . '/utilisateur/enregistrements/journal' . date('Y') . '.txt';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	if (!$_POST['csrf_token'] || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
		throw new \Exception('Erreur de sécurité: Token CSRF invalide');
	}

	$stmt = $db->prepare("UPDATE acquisition SET en_saisie = 0 WHERE id = ?");
	$stmt->bind_param("i", $id);
	$stmt->execute();

	$_SESSION['acquisition_en_saisie'] = 0;

	$sql = "UPDATE utilisateur SET acquisition_en_saisie = 0 WHERE username = ?";
	$stmt = $db->prepare($sql);
	$stmt->bind_param(
		"s",
		$utilisateur
	);
	$stmt->execute();

	$avis = "l'acquisition a été clôturée.";

	try {
		// Journalisation
		if (!empty($reference)) {
			$ajoutjournal = date('Y/m/d') . ' ' . $utilisateur . ' - ' . 'Clôture de l\'acquisition' . $reference . "(" . $id . ")" . PHP_EOL . 'Motif : ' . $remarque;

			// Écriture dans les journaux avec vérification des chemins
			$allowedPaths = [__DIR__ . '/utilisateur/enregistrements/'];
			$isValidPath = false;

			foreach ($allowedPaths as $path) {
				if (strpos($journalmat, $path) === 0 && strpos($journal, $path) === 0) {
					$isValidPath = true;
					break;
				}
			}

			if ($isValidPath) {
				file_put_contents($journalacquisition, '-------' . PHP_EOL . $ajoutjournal . PHP_EOL, FILE_APPEND | LOCK_EX);
				file_put_contents($journal, "---------" . PHP_EOL . $ajoutjournal . PHP_EOL, FILE_APPEND | LOCK_EX);
			}
		}
	} catch (Exception $e) {
		error_log("[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage());
		$avis = "Une erreur est survenue lors de la journalisation";
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
				<?= $avis ?>
			</div>
		<?php endif; ?>
		<p>
		<form method="post" action="<?= $retour ?>" id='form-controle'>
			<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
			<input type="hidden" name="acquisition_id" value="<?= $id ?>">
			<input type="hidden" name="reference" value="<?= $reference ?>">
			<input type="hidden" name="remarque" value="<?= $remarque ?>">
			<div class="actions">
				<?= $bouton ?>
				<button type="submit" name="accueil" class="btn return-btn">
					Revenir à l'accueil
				</button>
			</div>
		</form>
		</p>
	</main>
	<footer>
		<?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
	</footer>
	<script>
		// Validation client du formulaire
		document.getElementById('form-controle').addEventListener('submit', function(e) {
			const remarques = this.elements['remarque'].value.trim();

			if (remarques.length > 500) {
				alert('Les remarques ne doivent pas dépasser 500 caractères.');
				e.preventDefault();
			}
		});
	</script>
</body>


</html>