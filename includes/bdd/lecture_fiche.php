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
	function lecture_fiche(int $id, ?mysqli $connection = null): array {
		// 1. VALIDATION DE L'ENTRÉE
		if ($id <= 0) {
			return [
				'donnees' => null,
				'success' => false,
				'error' => 'ID invalide: doit être un entier positif'
			];
		}
		// 2. GESTION DE LA CONNEXION
		$shouldCloseConnection = false;
		
		try {
			if ($connection === null) {
				global $host, $username, $password, $dbname;
				
				// Configuration sécurisée de MySQLi
				mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
				$connection = new mysqli($host, $username, $password, $dbname);
				$connection->set_charset("utf8mb4");
				$shouldCloseConnection = true;
			}
			
			// 3. REQUÊTE PRÉPARÉE
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
			facture, 
			facture_id, 
			DATE_FORMAT(date_facture, '%Y-%m-%d') AS date_facture,
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
			
			$stmt = $connection->prepare($sql);
			$stmt->bind_param('i', $id);
			$stmt->execute();
			
			// 4. RÉCUPÉRATION DES RÉSULTATS
			$result = $stmt->get_result();
			$donnees = $result->fetch_assoc();
			$donnees['facture_id'] = (!$donnees['facture_id'] && $_SESSION['facture_en_saisie']) ? $_SESSION['facture_en_saisie'] : $donnees['facture_id'];
			return [
				'donnees' => $donnees, // Correction: utilisation de $donnees au affectation de $data
				'success' => $donnees !== null,
				'error' => $donnees ? '' : 'Aucune fiche trouvée avec cet ID'
			];
			
		} catch (mysqli_sql_exception $e) {
			// Journalisation et retour d'erreur
			error_log("Erreur DB lors de la lecture de la fiche ID $id: " . $e->getMessage());
			return [
				'donnees' => null,
				'success' => false,
				'error' => 'Erreur lors de la récupération des données'.$e->getMessage()
			];
		} finally {
			// 5. FERMETURE PROPRE DE LA CONNEXION SI NOUS L'AVONS CRÉÉE
			if ($shouldCloseConnection && $connection instanceof mysqli) {
				$connection->close();
			}
		}
	}