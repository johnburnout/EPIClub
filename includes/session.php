<?php
	
	// Configuration sécurisée des sessions
	session_start([
		'cookie_lifetime' => 86400, // 24h en secondes
		'cookie_secure' => true,	// Uniquement en HTTPS
		'cookie_httponly' => true,  // Empêche l'accès JS
		'use_strict_mode' => true,  // Protection fixation session
		'cookie_samesite' => 'Strict' // Protection CSRF
	]);
	
	// activation eventuelle du mode debug
	$dev = $_SESSION['dev'] ?? $_SESSION['dev'] ?? false;
	if ($dev) {
		// Activation des erreurs
		error_reporting(E_ALL);
		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
	}
	
	// Protection contre les attaques par fixation de session
	if (empty($_SESSION['regenerate_time'])) {
		session_regenerate_id(true);
		$_SESSION['regenerate_time'] = time();
	} elseif (time() - $_SESSION['regenerate_time'] > 1800) { // 30 minutes
		session_regenerate_id(true);
		$_SESSION['regenerate_time'] = time();
	}
	

	// Génération du token CSRF
	//if (empty($_SESSION['csrf_token'])) {
	//	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	//};
	
	// Gestion de la déconnexion
	if (isset($_POST['deconnexion'])) {
		$_SESSION = array();
		session_destroy();
		header('Location: index.php');
		exit;
	}
	
	// ##############################################
	// VÉRIFICATION DE LA CONNEXION UTILISATEUR
	// ##############################################

	
	$isLoggedIn = !empty($_SESSION['pseudo']);  // Vérifie si l'utilisateur est connecté
	$isAdmin = !empty($_SESSION['role']) ? ($_SESSION['role'] == 'admin') : false ;
	$connect = $isLoggedIn ? "Connecté comme ".htmlspecialchars($_SESSION['pseudo']) : "Déconnecté";
	$utilisateur = $isLoggedIn ? $_SESSION['pseudo'] : "Déconnecté";
	
	// ##############################################
	// RECUPERATION DU CSRF TOKEN
	// ############################################
	
	//$csrf_token = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : (isset($_POST['csrf_token']) ? $_POST['csrf_token'] : 0);
	// Remplacer la ligne actuelle par :
	$csrf_token = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '';
	if (!empty($csrf_token) && !preg_match('/^[a-f0-9]{64}$/', $csrf_token)) {
		$csrf_token = ''; // Invalide si ne correspond pas au format attendu
	}
?>