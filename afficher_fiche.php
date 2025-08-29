<?php

require __DIR__ . '/app/bootstrap.php';

if (!$isLoggedIn) {
	header('Location: /');
	exit();
}

if (isset($_GET['id'])) {
	if ($_SESSION['controle_en_cours']) {
		header('Location: controle_epi.php?id=' . $_GET['id'] . '&action=controler&csrf_token=' . $csrf_token);
		exit();
	} else {
		header('Location: fiche_epi.php?id=' . $_GET['id'] . '&action=affichage&retour=liste_epis.php&csrf_token=' . $csrf_token);
		exit();
	}
}

header('Location: /');
exit();
