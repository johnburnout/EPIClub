<?php
	
	// Inclusion des fichiers de configuration
	require __DIR__.'/config.php';
	require __DIR__."/includes/debug.php";
	require __DIR__."/includes/session.php";
	
	if (!$isLoggedIn) {
		header('Location: login.php');
		exit();
	}
	elseif (isset($_GET['id'])) {
		if ($_SESSION['controle_en_cours']) {
			header('Location: controle_epi.php?id='.$_GET['id'].'&action=controler&csrf_token='.$csrf_token);
			exit();
		}
		else {
			header('Location: fiche_epi.php?id='.$_GET['id'].'&action=affichage&retour=liste_epis.php&csrf_token='.$csrf_token);
			exit();
		}
	}
	else {
		header('Location: index.php');
		exit();
	}
	
	// #############################
	// Initialisation variables
	// #############################
	
?>