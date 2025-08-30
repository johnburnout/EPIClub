<?php

/**
 * Lit une fiche spécifique depuis la base de données
 * 
 * @param int $id Identifiant de la fiche à récupérer
 * @param string $utilisateur Nom de l'utilisateur à vérifier
 * @return array [
 *	 'donnees' => array|null, // Données de la fiche ou null si non trouvée
 *	 'success' => bool,	   // Statut de l'opération
 *	 'error' => string		// Message d'erreur le cas échéant
 * ]
 */
function lecture_controle(int $id, string $utilisateur, mysqli $db): array
{
	//global $db;

	if ($id <= 0) {  // Changement à <= 0 car un ID doit être positif
		return ['donnees' => null, 'success' => false, 'error' => 'ID invalide'];
	}

	if (empty(trim($utilisateur))) {
		return ['donnees' => null, 'success' => false, 'error' => 'Nom d\'utilisateur vide'];
	}

	$sql = "SELECT 
			id,
			utilisateur, 
			date_controle,
			remarques,
			en_cours,
			epi_controles
			FROM controle 
			WHERE id = ? AND utilisateur = ? AND en_cours = 1
			LIMIT 1";  // Changement de ORDER BY à LIMIT 1 car on cherche un enregistrement spécifique

	$stmt = $db->prepare($sql);
	$stmt->bind_param('is', $id, $utilisateur);

	if (!$stmt->execute()) {
		throw new Exception("Erreur d'exécution de la requête: " . $stmt->error);
	}

	$result = $stmt->get_result();
	$donnees = $result->fetch_assoc();

	if (!$donnees) {
		return [
			'donnees' => null,
			'success' => false,
			'error' => 'Aucun contrôle en cours trouvé'
		];
	}

	if (!empty($donnees['date_controle'])) {
		$donnees['date_controle'] = date('Y-m-d', strtotime($donnees['date_controle']));
	}

	return [
		'donnees' => $donnees,
		'success' => true,
		'error' => ''
	];
}

function lecture_controle_recent($id, $utilisateur)
{
	global $db;

	$sql = "SELECT 
			id,
			utilisateur, 
			date_controle,
			remarques,
			en_cours,
			epi_controles
		FROM controle 
		WHERE id = ? 
		AND utilisateur = ? 
		AND (date_controle IS NULL OR date_controle >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 YEAR))
		LIMIT 1";

	$stmt = $db->prepare($sql);
	$stmt->bind_param('is', $id, $utilisateur);
	$stmt->execute();

	$result = $stmt->get_result();
	$donnees = $result->fetch_assoc();

	if (!$donnees) {
		return [
			'donnees' => null,
			'success' => false,
			'error' => 'Aucun contrôle récent (moins d\'un an) trouvé'
		];
	}

	return [
		'donnees' => $donnees,
		'success' => true,
		'error' => ''
	];
}
