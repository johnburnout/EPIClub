<?php

/**
 * Met à jour une fiche utilisateur
 * 
 * @param array $donnees Tableau associatif des données à mettre à jour contenant :
 *               - 'username' : string nom d'utilisateur (requis)
 *               - 'password' : string mot de passe (requis)
 *               - 'email' : string email (requis)
 *               - 'role' : string role (requis)
 *               - 'est_actif' : int (requis)
 * @param int $id ID de l'utilisateur à modifier
 * @return array [
 *     'success' => bool,        // Statut de l'opération
 *     'affected_rows' => int,   // Nombre de lignes affectées
 *     'error' => string         // Message d'erreur le cas échéant
 * ]
 */
function mise_a_jour_utilisateur(array $donnees, int $id, mysqli $db)
{
	//global $db;

	if ($id <= 0) {
		return [
			'success' => false,
			'affected_rows' => 0,
			'error' => 'ID de fiche invalide'
		];
	}

	$checkUsernameStmt = $db->prepare("SELECT id FROM utilisateur WHERE username = ? AND id != ?");
	$checkUsernameStmt->bind_param("si", $donnees['username'], $id);
	$checkUsernameStmt->execute();

	$usernameResult = $checkUsernameStmt->get_result();

	if ($usernameResult->num_rows > 0) {
		return [
			'success' => false,
			'affected_rows' => 0,
			'error' => 'Ce nom d\'utilisateur est déjà utilisé par un autre compte'
		];
	}

	$checkEmailStmt = $db->prepare("SELECT id FROM utilisateur WHERE email = ? AND id != ?");
	$checkEmailStmt->bind_param("si", $donnees['email'], $id);
	$checkEmailStmt->execute();

	$emailResult = $checkEmailStmt->get_result();

	if ($emailResult->num_rows > 0) {
		return [
			'success' => false,
			'affected_rows' => 0,
			'error' => 'Cette adresse email est déjà utilisée par un autre compte'
		];
	}

	$champsObligatoires = [
		'username' => 'string',
		'email' => 'string',
		'role' => 'string',
		'est_actif' => 'int'
	];

	$isMdp = !empty($donnees['password']);

	foreach ($champsObligatoires as $champ => $type) {
		if (!isset($donnees[$champ])) {
			return [
				'success' => false,
				'affected_rows' => 0,
				'error' => "Champ obligatoire manquant: $champ"
			];
		}

		$functionValidation = "is_$type";
		if (!$functionValidation($donnees[$champ])) {
			return [
				'success' => false,
				'affected_rows' => 0,
				'error' => "Type invalide pour $champ ($type attendu)"
			];
		}
	}

	$password_hash = password_hash($donnees['password'], PASSWORD_DEFAULT) ?? '';

	$sql = $isMdp ?
		"UPDATE utilisateur SET
	                username = ?,
	                password = ?,
	                email = ?,
	                role = ?,
	                est_actif = ?
	                WHERE id = ?" :
		"UPDATE utilisateur SET
	                username = ?,
	                email = ?,
	                role = ?,
	                est_actif = ?
	                WHERE id = ?";

	$stmt = $db->prepare($sql);

	if ($isMdp) {
		$stmt->bind_param(
			"ssssii",
			$donnees['username'],
			$password_hash,
			$donnees['email'],
			$donnees['role'],
			$donnees['est_actif'],
			$id
		);
	} else {
		$stmt->bind_param(
			"sssii",
			$donnees['username'],
			$donnees['email'],
			$donnees['role'],
			$donnees['est_actif'],
			$id
		);
	}
	$stmt->execute();

	$affectedRows = $stmt->affected_rows;

	return [
		'success' => $affectedRows > 0,
		'affected_rows' => $affectedRows,
		'error' => $affectedRows > 0 ? '' : 'Aucune ligne mise à jour'
	];
}
