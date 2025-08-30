<?php

/**
 * Génère des options HTML pour une liste déroulante à partir d'une table de base de données
 * 
 * @param array $entree Tableau contenant :
 *			   - 'libelles' : nom de la table source
 *			   - 'id' : ID à sélectionner (optionnel)
 * @return array [options_html, success, error_message]
 */
function liste_options(array $entree, mysqli $db): array
{
	//global $db;

	if (!isset($entree['libelles'])) {
		return ['', false, 'Le paramètre "libelles" est obligatoire'];
	}

	$id_selection = (int)($entree['id'] ?? 0);
	$libelles = $entree['libelles'];

	// Validation sécurité du nom de table
	if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $libelles)) {
		throw new InvalidArgumentException("Nom de table invalide");
	}

	$query = "SELECT id, libelle FROM `" . $db->real_escape_string($libelles) . "` ORDER BY id";
	$statement = $db->query($query);

	$options = [];
	$tableau = [];

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
}
