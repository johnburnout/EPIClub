<?php

require __DIR__ . '/app/bootstrap.php';

if (!$isLoggedIn) {
	header('Location: /');
	exit();
}

if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
	throw new Exception('Erreur de sécurité: Token CSRF invalide');
}


$total_journaux = 0;
$annees_journaux = [];

for ($i = 0; $i < 5; $i++) {
	$annee = date('Y', strtotime("-$i year"));
	$annees_journaux[] = [
		'annee' => $annee,
		'chemin' => "utilisateur/enregistrements/" . 'journal' . $annee . '.txt',
		'existe' => file_exists(__DIR__ . '/utilisateur/enregistrements/' . 'journal' . $annee . '.txt')
	];
	$total_journaux += $annees_journaux[$i]['existe'];
}

try {
	$sql = "SELECT 
		id,
		utilisateur, 
		date_controle,
		remarques,
		en_cours,
		epi_controles
		FROM controle 
		WHERE date_controle >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 YEAR)
		AND epi_controles IS NOT NULL 
		AND epi_controles != ''";
	$stmt = $db->prepare($sql);
	$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
	$donnees = $result; //->fetch_assoc();
	$total_controles = count($donnees);
	$success = true;
	$error = '';

	if (!$donnees) {
		$success = false;
		$error = 'Aucun contrôle récent (moins d\'un an) trouvé';
	}

	// Formatage de la date si elle existe
	if (!empty($donnees['date_controle'])) {
		$donnees['date_controle'] = date('Y-m-d', strtotime($donnees['date_controle']));

		// Vérification supplémentaire côté PHP (redondante mais prudente)
		$dateControle = new DateTime($donnees['date_controle']);
		$dateLimite = (new DateTime())->sub(new DateInterval('P1Y'));

		if ($dateControle < $dateLimite) {
			$success = false;
			$error = 'Le contrôle trouvé est trop ancien (plus d\'un an)';
		}
	}
} catch (\Exception $e) {
	error_log("Erreur: " . $e->getMessage());
	return ['donnees' => null, 'success' => false, 'error' => $e->getMessage()];
}

$controles_journaux = [];
for ($i = 0; $i < $total_controles; $i++) {
	$controles_journaux[] = [
		'controle' => $donnees[$i]['date_controle'],
		'chemin' => "utilisateur/enregistrements/" . 'journalcontrole' . $donnees[$i]['id'] . '.txt',
		'existe' => file_exists(__DIR__ . '/utilisateur/enregistrements/' . 'journalcontrole' . $donnees[$i]['id'] . '.txt')
	];
}

$lignes = max($total_journaux, $total_controles);
$tableau = [];

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
		<form method="get">
			<?php include __DIR__ . '/includes/en_tete.php'; ?>
			<h3>Journaux</h3>
			<div class="card h-100">
				<div class="card-body">
					<table>
						<thead>
							<tr>
								<th>Généraux</th>
								<th>Contrôles</th>
							</tr>
						</thead>
						<tbody>
							<?php for ($i = 0; $i < $lignes; $i++): ?>
								<tr>
									<!-- Colonne Année -->
									<td>
										<?php if (!empty($annees_journaux[$i]['existe'])): ?>
											<a href="affichage_texte.php?csrf_token=<?= htmlspecialchars($csrf_token) ?>&url=<?= urlencode($annees_journaux[$i]['chemin']) ?>&retour=affichage_journaux.php&id=0">
												<input type="button"
													name="<?= htmlspecialchars($annees_journaux[$i]['annee'] ?? 'N/A') ?>"
													value="<?= htmlspecialchars($annees_journaux[$i]['annee'] ?? 'N/A') ?>"
													class="btn btn-secondary"
												>
											</a>
										<?php endif; ?>
									</td>
									<!-- Colonne Contrôle -->
									<td>
										<?php if (!empty($controles_journaux[$i]['existe'])): ?>
											<a href="affichage_texte.php?csrf_token=<?= htmlspecialchars($csrf_token) ?>&url=<?= urlencode($controles_journaux[$i]['chemin']) ?>&retour=affichage_journaux.php&id=0">
												<input type="button"
													name="<?= htmlspecialchars($controles_journaux[$i]['controle'] ?? 'N/A') ?>"
													value="<?= htmlspecialchars($controles_journaux[$i]['controle'] ?? 'N/A') ?>"
													class="btn btn-primary" />
											</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
				</div>
			</div>
			<div class="actions">
				<a href="/" class="btn return-btn">Retour à l'accueil</a>
			</div>
		</form>
	</main>
	<footer>
		<?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
	</footer>
</body>

</html>