<?php
	
	require __DIR__.'/config.php';
	require __DIR__."/includes/debug.php";
	require __DIR__."/includes/session.php";
	
	if ($isLoggedIn) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		$csrf_token = $_SESSION['csrf_token'] ?? '';
		$controle_id = isset($_SESSION['controle_en_cours']) ? (int)$_SESSION['controle_en_cours'] : 0;
		$facture_id = isset($_SESSION['facture_en_saisie']) ? (int)$_SESSION['facture_en_saisie'] : 0;
		$controle = $controle_id ? "liste_controle.php" : "controle_creation.php" ;
		$facture = $facture_id ? "liste_facture.php" : "facture_creation.php" ;
	}
?>

<!DOCTYPE html>
<html lang="fr">
	<head>
		<?php include __DIR__.'/includes/head.php'; ?>
	</head>
	<body>
		<header style="text-align: right; padding: 10px;">
			<?php include __DIR__.'/includes/bandeau.php'; ?>
		</header>
		<main class="container">
			<?php include __DIR__.'/includes/en_tete.php'; ?>
			
			<?php if (!$isLoggedIn): ?>
			<section class="test-version">
				<h3>Version de test</h3>
				<div class="alert alert-info">
					<p>
						<strong>Pour vous connecter comme contrôleur EPI :</strong><br>
						login : usager | mdp : usager
					</p>
					<p>
						<strong>Pour vous connecter comme admin EPI :</strong><br>
						login : admin | mdp : admin
					</p>
					<p>
						Version 0.9.2 (beta) en cours de test (22/08/2025).
					</p>
					<p>
						Pour rapporter des bugs ou obtenir une version d'essai, contactez <a href="mailto:jean@grimpe.fr">Jean Roussie</a>.
					</p>
				</div>
			</section>
			<?php endif; ?>
			<?php if ($isLoggedIn): ?>
			<section class="dashboard">
				<?php if($controle_id || $facture_id): ?>
				<div class="alert alert-error">
					Vous avez commencé à <?= $controle_id ? "controler des EPI" : "" ?><?= $controle_id && $facture_id ? " et " : "" ?><?= $facture_id ? "saisir une facture" : "" ?>.
				</div>
				<?php endif; ?>
				<h3>Accueil</h3>
				<div class="card">
					<h5 class="card-title">Consultation</h5>
					<form action="liste_epis.php" method="post">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
						<button type="submit" class="btn btn-primary btn-block">
							Consulter la liste des EPI
						</button>
					</form>
				</div>
				<p></p>
				<div class="card ">
					<h5 class="card-title">Contrôles</h5>
					<form action="<?= htmlspecialchars($controle) ?>" method="post" class="mb-3">
						<input type="hidden" name="action" value="creation">
						<input type="hidden" name="controle_id" value="<?= htmlspecialchars($_SESSION['controle_en_cours'] ?? '') ?>">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
						<button type="submit" class="btn btn-primary btn-block">
							Contrôler les EPI
						</button>
					</form>
					<p></p>
					<form action="affichage_journaux.php" method="post" class="mb-3">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
						<button type="submit" class="btn btn-primary btn-block">
							Journaux
						</button>
					</form>
					<?php if ($isAdmin): ?>
					<p></p>
					<form action="<?= htmlspecialchars($facture) ?>" method="post">
						<input type="hidden" name="action" value="creation">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
						<button type="submit" class="btn btn-secondary btn-block">
							Saisir une facture
						</button>
					</form>
					<?php endif; ?>
				</div>
				<p></p>
				<!-- Carte Administration (visible seulement pour les admins) -->
				<?php if ($isAdmin): ?>
				<div class="card">
					<h5 class="card-title">Administration</h5>
					
					<form action="liste_utilisateurs.php" method="post" class="mb-3">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
						<button type="submit" class="btn btn-secondary btn-block">
							Gestion des utilisateurs
						</button>
					</form>
					<p></p>
					<form action="liste_fabricants.php" method="post" class="mb-3">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
						<button type="submit" class="btn btn-secondary btn-block">
							Gestion des fabricants
						</button>
					</form>
					<p></p>
					<form action="liste_categories.php" method="post">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
						<button type="submit" class="btn btn-secondary btn-block">
							Gestion des catégories
						</button>
					</form>
					<p></p>
					<form action="liste_affectations.php" method="post">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
						<button type="submit" class="btn btn-secondary btn-block">
							Gestion des affectations
						</button>
					</form>
				</div>
				<?php endif; ?>
			</section>
			<?php endif; ?>
		</main>
		<footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		</footer>
	</body>
</html>