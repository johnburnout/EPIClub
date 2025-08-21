<?php
	require __DIR__.'/config.php';
	require __DIR__.'/includes/communs.php';
	
		// Vérification des permissions
		// Validation CSRF
		if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
	    	throw new Exception('Erreur de sécurité: Token CSRF invalide');
		}
	
		if (!$isLogedIn) {
	    	header('Location: index.php?');
	    	exit();
		}
		
	if (!isset($_GET['file'])) {
	    die('Paramètre fichier manquant');
	}
	
	$baseDir = realpath(__DIR__.'/enregistrements/');
	$cheminComplet = filter_var($_GET['file'], FILTER_SANITIZE_URL);
	
	// Vérification de sécurité
	if (!preg_match('~^/([a-zA-Z0-9_\-\./]+)$~', $cheminComplet) || 
	    strpos(realpath($cheminComplet), $baseDir) !== 0 || 
	    !file_exists($cheminComplet)) {
	    header('HTTP/1.0 404 Not Found');
	    die('Fichier non trouvé ou accès non autorisé');
	}
	
	// En-têtes pour le téléchargement
	header('Content-Description: File Transfer');
	header('Content-Type: text/plain');
	header('Content-Disposition: attachment; filename="'.basename($cheminComplet).'"');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Length: ' . filesize($cheminComplet));
	readfile($cheminComplet);
	exit;
?>