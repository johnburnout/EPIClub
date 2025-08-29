<?php

if(session_status() === PHP_SESSION_NONE) session_start();

// Génération du token CSRF
if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
};

$isLoggedIn = isset($_SESSION['user']);  // Vérifie si l'utilisateur est connecté
$isAdmin = $isLoggedIn && $_SESSION['user']['role'] === 'admin';
$csrf_token = $_SESSION['csrf_token'];

if ($isLoggedIn && isset($_SESSION['expire']) && time() > $_SESSION['expire']) {
	session_destroy();
	header('Location: /');
	exit();
}