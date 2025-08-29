<?php

require __DIR__ . '/app/bootstrap.php';

if (!$isLoggedIn) {
	header('Location: /');
	exit();
}

/** @deprecated Don't use it anymore! */
/*
if ($isLoggedIn) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	$csrf_token = $_SESSION['csrf_token'] ?? '';
	$controle_id = isset($_SESSION['controle_en_cours']) ? (int)$_SESSION['controle_en_cours'] : 0;
	$acquisition_id = isset($_SESSION['acquisition_en_saisie']) ? (int)$_SESSION['acquisition_en_saisie'] : 0;
	$controle = $controle_id ? "" : "controle_creation.php";
	$acquisition = $acquisition_id ? "liste_acquisition.php" : "acquisition_creation.php";
} */

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
	<main class="container">
		<?php include __DIR__ . '/includes/en_tete.php'; ?>
		<?php if ($isLoggedIn): ?>
			<section class="dashboard">
				<?php /**if ($controle_id || $acquisition_id): ?>
					<div class="alert alert-error">
						Vous avez commencé à <?= $controle_id ? "controler des EPI" : "" ?><?= $controle_id && $acquisition_id ? " et " : "" ?><?= $acquisition_id ? "saisir une acquisition" : "" ?>.
					</div>
				<?php endif;*/ ?>
				<h3>Accueil</h3>
				<div class="card">
					<h5 class="card-title">Consultation</h5>
					<a href="/liste_epis.php" class="btn btn-primary btn-block">Consulter la liste des EPI</a>
				</div>
				<div class="card ">
					<h5 class="card-title">Contrôles</h5>
					<a href="/liste_controles.php" class="btn btn-primary btn-block">Contrôler les EPI</a>
					<a href="/affichage_journaux.php" class="btn btn-primary btn-block">Journaux</a>
				</div>
				<div class="card">
					<?php if ($isAdmin): ?>
						<h5 class="card-title">Administration</h5>
						<a href="/liste_acquisitions.php" class="btn btn-secondary btn-block">Gestion des acquisitions</a>
						<a href="/liste_utilisateurs.php" class="btn btn-secondary btn-block">Gestion des utilisateurs</a>
						<a href="/liste_fabricants.php" class="btn btn-secondary btn-block">Gestion des fabricants</a>
						<a href="/liste_categories.php" class="btn btn-secondary btn-block">Gestion des catégories</a>
						<a href="/liste_affectations.php" class="btn btn-secondary btn-block">Gestion des affectations</a>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>
	</main>
	<footer>
		<?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
	</footer>
</body>

</html>