<?php
	
/**
* Génère des options HTML pour une liste déroulante à partir d'une table de base de données
* 
* @param array $entree Tableau contenant :
*			   - 'libelles' : nom de la table source
*			   - 'id' : ID à sélectionner (optionnel)
* @return array [options_html, success, error_message]
*/
function liste_options(array $entree, ?mysqli $connection = null): array {
	// Validation des paramètres obligatoires
	if (!isset($entree['libelles'])) {
		return ['', false, 'Le paramètre "libelles" est obligatoire'];
	}
	
	// Valeurs par défaut
	$id_selection = (int)($entree['id'] ?? 0);
	$libelles = $entree['libelles'];
	
	// #############################
	// Connexion à la base de données
	// #############################
	try {
		if ($connection === null) {
			global $host, $username, $password, $dbname;
			
			mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
			$connection = new mysqli($host, $username, $password, $dbname);
			$connection->set_charset("utf8mb4");
			$shouldCloseConnection = true;
		}

		// Validation sécurité du nom de table
		if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $libelles)) {
			throw new InvalidArgumentException("Nom de table invalide");
		}
		
		// Requête préparée pour plus de sécurité
		$query = "SELECT id, libelle FROM `".$connection->real_escape_string($libelles)."` ORDER BY id";
		$statement = $connection->query($query);
		// Génération des options
		$options = [];
		$tableau = [];
		//$tableau = $result->fetch_assoc();
		while ($item = $statement->fetch_assoc()) {
			$tableau[] = $item;
			$selected = ($item['id'] == $id_selection) ? ' selected' : '';
			$options[] = sprintf(
				'<option value="%d"%s>%s</option>',
				$item['id'],
				$selected,
				htmlspecialchars($item['libelle'], ENT_QUOTES)
			);
		}
		
		return [implode('', $options), $tableau, true, ''];
		
	} catch (mysqli_sql_exception $e) {
		return ['', [], false, 'Erreur base de données : '.$e->getMessage()];
	} catch (Exception $e) {
		return ['', [], false, 'Erreur : '.$e->getMessage()];
	} finally {
		if (isset($shouldCloseConnection) && $shouldCloseConnection && isset($connection)) {
			$connection->close();
		}
	}
}
?>