<?php

/**
 * Lit une fiche spécifique depuis la base de données
 * 
 * @param int $id Identifiant de la fiche à récupérer
 * @param mysqli $connection (Optionnel) Connexion MySQLi existante
 * @return array [
 *	 'donnees' => array|null, // Données de la fiche ou null si non trouvée
 *	 'success' => bool,	   // Statut de l'opération
 *	 'error' => string		// Message d'erreur le cas échéant
 * ]
 */
function lecture_fiche(int $id, ?mysqli $connection = null): array
{
	global $db;

	if ($id <= 0) {
		return [
			'donnees' => null,
			'success' => false,
			'error' => 'ID invalide: doit être un entier positif'
		];
	}

	$sql = "SELECT 
			ref AS reference, 
			en_service,
			libelle, 
			categorie, 
			categorie_id, 
			fabricant, 
			fabricant_id, 
			affectation, 
			affectation_id, 
			acquisition, 
			acquisition_id, 
			DATE_FORMAT(date_acquisition, '%Y-%m-%d') AS date_acquisition,
			nb_elements, 
			nb_elements_initial, 
			DATE_FORMAT(date_max, '%Y-%m-%d') AS date_max,
			DATE_FORMAT(date_debut, '%Y-%m-%d') AS date_debut,
			controle_id, 
			DATE_FORMAT(date_controle, '%Y-%m-%d') AS date_controle,
			remarques, 
			photo
			FROM fiche 
			WHERE id = ?";

	$stmt = $db->prepare($sql);
	$stmt->bind_param('i', $id);
	$stmt->execute();

	$result = $stmt->get_result();
	$donnees = $result->fetch_assoc();
	$donnees['acquisition_id'] = (!$donnees['acquisition_id'] && $_SESSION['acquisition_en_saisie']) ? $_SESSION['acquisition_en_saisie'] : $donnees['acquisition_id'];

	return [
		'donnees' => $donnees, // Correction: utilisation de $donnees au affectation de $data
		'success' => $donnees !== null,
		'error' => $donnees ? '' : 'Aucune fiche trouvée avec cet ID'
	];
}
