<?php
/**
 * Fonction de débogage
 * @param mixed $var Variable à déboguer
 * @return bool Retourne toujours true
 */
function dev($var): bool {
	// Vérification des droits de débogage
	$dev = $_SESSION['dev'] ?? $_SESSION['dev'] ?? false;
	
	if ($dev) {
		// Affichage formaté de la variable
		echo '<pre>';
		var_dump($var);
		echo PHP_EOL.'-----------'.PHP_EOL;
		echo '</pre>';
	}
	
	return true;
}