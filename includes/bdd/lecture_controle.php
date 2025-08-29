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
	function lecture_controle(int $id, string $utilisateur, ?mysqli $connection = null): array {
		// Validation des paramètres
		if ($id <= 0) {  // Changement à <= 0 car un ID doit être positif
			return ['donnees' => null, 'success' => false, 'error' => 'ID invalide'];
		}
		
		if (empty(trim($utilisateur))) {
			return ['donnees' => null, 'success' => false, 'error' => 'Nom d\'utilisateur vide'];
		}
		
		try {
			// Connexion à la base de données
			if ($connection === null) {
				global $host, $username, $password, $dbname;
				
				mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
				$connection = new mysqli($host, $username, $password, $dbname);
				$connection->set_charset("utf8mb4");
				$shouldCloseConnection = true;
			}
			
			// Requête préparée
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
			
			$stmt = $connection->prepare($sql);
			if (!$stmt) {
				throw new Exception("Erreur de préparation de la requête: " . $connection->error);
			}
			
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
			
			// Formatage de la date si elle existe
			if (!empty($donnees['date_controle'])) {
				$donnees['date_controle'] = date('Y-m-d', strtotime($donnees['date_controle']));
			}
			
			return [
				'donnees' => $donnees,
				'success' => true,
				'error' => ''
			];
			
		} catch (mysqli_sql_exception $e) {
			error_log("Erreur MySQL: " . $e->getMessage());
			return ['donnees' => null, 'success' => false, 'error' => 'Erreur de base de données'];
		} catch (Exception $e) {
			error_log("Erreur: " . $e->getMessage());
			return ['donnees' => null, 'success' => false, 'error' => $e->getMessage()]; // Retourne le message spécifique
		} finally {
			if (isset($connection) && $connection instanceof mysqli) {
				$connection->close();
			}
		}
	}
	
	function lecture_controle_recent($id, $utilisateur) {
		global $host, $username, $password, $dbname;
		
		try {
			$connection = new mysqli($host, $username, $password, $dbname);
			$connection->set_charset("utf8mb4");
			
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
			
			$stmt = $connection->prepare($sql);
			if (!$stmt) {
				throw new Exception("Erreur de préparation de la requête: " . $connection->error);
			}
			
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
			
		} catch (Exception $e) {
			return ['donnees' => null, 'success' => false, 'error' => $e->getMessage()];
		} finally {
			if (isset($connection)) {
				$connection->close();
			}
		}
	}