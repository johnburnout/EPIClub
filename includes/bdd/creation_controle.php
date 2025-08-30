<?php

/**
 * Crée une nouvelle fiche dans la base de données
 * 
 * @param array $donnees Tableau associatif contenant les données de la fiche
 * @return array [
 *	 'id' => int,		// ID de la fiche créée (0 en cas d'échec)
 *	 'success' => bool,  // Statut de l'opération
 *	 'error' => string   // Message d'erreur le cas échéant
 * ]
 */
function creation_controle(array $donnees, mysqli $db): array
{
	//global $db;

	$requiredFields = [
		'utilisateur' => 'string'
	];

	foreach ($requiredFields as $field => $type) {
		if (!isset($donnees[$field])) {
			return ['id' => 0, 'success' => false, 'error' => "Champ obligatoire manquant: $field"];
		}

		if ($type === 'integer' && !is_numeric($donnees[$field])) {
			return ['id' => 0, 'success' => false, 'error' => "Type invalide pour $field (nombre attendu)"];
		}

		if ($type === 'string' && !is_scalar($donnees[$field])) {
			return ['id' => 0, 'success' => false, 'error' => "Type invalide pour $field (chaîne attendue)"];
		}
	}

	$sql1 = "INSERT INTO controle (utilisateur) VALUES (?)";
	$stmt1 = $db->prepare($sql1);
	$stmt1->bind_param("s", $donnees['utilisateur']);
	if (!$stmt1->execute()) {
		throw new Exception("Erreur MySQL: " . $stmt1->error);
	}

	$id = $db->insert_id;

	$sql2 = "UPDATE utilisateur SET controle_en_cours = ? WHERE username = ?";
	$stmt2 = $db->prepare($sql2);
	$stmt2->bind_param("is", $id, $donnees['utilisateur']);
	if (!$stmt2->execute()) {
		throw new Exception("Erreur MySQL: " . $stmt2->error);
	}

	return ['id' => $id, 'success' => true, 'error' => ''];
}
