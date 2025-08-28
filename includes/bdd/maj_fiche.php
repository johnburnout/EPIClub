<?php
declare(strict_types=1);

/**
* Met à jour une fiche matériel dans la base de données avec validation avancée
* 
* @param array $donnees Tableau associatif des données à mettre à jour
* @param mysqli|null $connection Connexion MySQLi existante (optionnelle)
* @return array [
*	 'success' => bool,		// Statut de l'opération
*	 'affected_rows' => int,   // Nombre de lignes affectées
*	 'error' => string		 // Message d'erreur le cas échéant
* ]
*/
function mise_a_jour_fiche(array $donnees, ?mysqli $connection = null): array {
	// 1. VALIDATION DES ENTREES
	if ($donnees['id'] <= 0) {
		return [
			'success' => false, 
			'affected_rows' => 0, 
			'error' => 'ID de fiche invalide (doit être un entier positif)'
		];
	}

	// 2. CONFIGURATION ET VALIDATION
	$champsObligatoires = [
		'reference' => ['type' => 'string', 'max' => 50],
		'libelle' => ['type' => 'string', 'max' => 255],
		'categorie_id' => ['type' => 'integer'],
		'fabricant_id' => ['type' => 'integer']
	];
	
	$champsOptionnels = [
		'photo' => ['type' => 'string', 'max' => 255],
		'affectation_id' => ['type' => 'integer'],
		'date_debut' => ['type' => 'date'],
		'nb_elements_initial' => ['type' => 'integer', 'min' => 1],
		'nb_elements' => ['type' => 'integer', 'min' => 0],
		'acquisition_id' => ['type' => 'integer'],
		'remarques' => ['type' => 'string', 'max' => 1000],
		'controle_id' => ['type' => 'integer'],
		'utilisateur' => ['type' => 'string', 'max' => 50]
	];

	// Validation des champs obligatoires
	foreach ($champsObligatoires as $champ => $config) {
		if (!isset($donnees[$champ])) {
			return [
				'success' => false,
				'affected_rows' => 0,
				'error' => "Champ obligatoire manquant: $champ"
			];
		}
		
		// Validation des types et contraintes
		$erreur = valider_champ($donnees[$champ], $config);
		if ($erreur !== null) {
			return [
				'success' => false,
				'affected_rows' => 0,
				'error' => "Erreur de validation pour $champ: $erreur"
			];
		}
	}
	
	// 3. GESTION DE LA CONNEXION
	$shouldCloseConnection = false;
	try {
		if ($connection === null) {
			global $host, $username, $password, $dbname;
			
			mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
			$connection = new mysqli($host, $username, $password, $dbname);
			$connection->set_charset("utf8mb4");
			$shouldCloseConnection = true;
		}
		
		// 4. VERIFICATION DE L'UNICITE DE LA REFERENCE
		$checkSql = "SELECT id FROM matos WHERE reference = ? AND id != ?";
		$checkStmt = $connection->prepare($checkSql);
		
		if (!$checkStmt) {
			throw new Exception("Erreur de préparation de la requête de vérification: " . $connection->error);
		}
		
		$checkStmt->bind_param("si", $donnees['reference'], $donnees['id']);
		$checkStmt->execute();
		$checkStmt->store_result();
		
		if ($checkStmt->num_rows > 0) {
			return [
				'success' => false,
				'affected_rows' => 0,
				'error' => 'Cette référence existe déjà pour un autre matériel'
			];
		}
		
		// 5. PRÉPARATION DE LA REQUÊTE DE MISE À JOUR
		$sql = "UPDATE matos SET
		reference = ?,
		libelle = ?,
		categorie_id = ?,
		fabricant_id = ?,
		photo = ?,
		affectation_id = ?,
		date_debut = ?,
		date_max = ?,
		nb_elements_initial = ?,
		nb_elements = ?,
		acquisition_id = ?,
		remarques = ?,
		date_modification = NOW(),
		controle_id = ?,
		utilisateur = ?
		WHERE id = ?";
		
		$stmt = $connection->prepare($sql);
		if (!$stmt) {
			throw new Exception("Erreur de préparation de la requête: " . $connection->error);
		}

		// 6. FORMATAGE DES DONNÉES
		$reference = $donnees['reference'];
		$libelle = $donnees['libelle'];
		$categorie_id = (int)$donnees['categorie_id'];
		$fabricant_id = (int)$donnees['fabricant_id'];
		$photo = $donnees['photo'] ?? null;
		$affectation_id = isset($donnees['affectation_id']) ? (int)$donnees['affectation_id'] : null;
		$dateDebut = date('Ymd', strtotime($donnees['date_debut']));
		$dateMax = date('Ymd', strtotime($donnees['date_max']));
		$nb_elements_initial = isset($donnees['nb_elements_initial']) ? (int)$donnees['nb_elements_initial'] : 1;
		$nb_elements = isset($donnees['nb_elements']) ? (int)$donnees['nb_elements'] : $nb_elements_initial;
		$acquisition_id = isset($donnees['acquisition_id']) ? (int)$donnees['acquisition_id'] : null;
		$remarques = $donnees['remarques'] ?? null;
		$controle_id = isset($donnees['controle_id']) ? (int)$donnees['controle_id'] : null;
		$utilisateur = $donnees['utilisateur'] ?? null;
		$id = (int)$donnees['id'];
		
		// 7. EXÉCUTION DE LA REQUÊTE
		$stmt->bind_param(
			"ssiisssiiiisisi",
			$reference,
			$libelle,
			$categorie_id,
			$fabricant_id,
			$photo,
			$affectation_id,
			$dateDebut,
			$dateMax,
			$nb_elements_initial,
			$nb_elements,
			$acquisition_id,
			$remarques,
			$controle_id,
			$utilisateur,
			$id
		);
		
		$stmt->execute();
		$affectedRows = $stmt->affected_rows;
		
		// 8. VÉRIFICATION DU RÉSULTAT
		if ($affectedRows === -1) {
			throw new Exception("Erreur lors de la mise à jour");
		}
		
		return [
			'success' => $affectedRows >= 0,
			'affected_rows' => $affectedRows,
			'error' => $affectedRows >= 0 ? '' : 'Aucune ligne mise à jour (ID peut-être inexistant)'
		];
		
	} catch (mysqli_sql_exception $e) {
		error_log("Erreur MySQL lors de la mise à jour fiche ".$donnees['id']." : " . $e->getMessage());
		return [
			'success' => false,
			'affected_rows' => 0,
			'error' => 'Erreur de base de données: '. $e->getMessage()
		];
	} catch (Exception $e) {
		error_log("Erreur lors de la mise à jour fiche ".$donnees['id'].": " . $e->getMessage());
		return [
			'success' => false,
			'affected_rows' => 0,
			'error' => $e->getMessage()
		];
	} finally {
		// 9. FERMETURE PROPRE DE LA CONNEXION SI NÉCESSAIRE
		if ($shouldCloseConnection && $connection instanceof mysqli) {
			$connection->close();
		}
	}
}

	/**
	* Valide un champ selon sa configuration
	*/
	function valider_champ($valeur, array $config): ?string {
		$type = $config['type'] ?? null;
		
		if ($type === 'integer' && !filter_var($valeur, FILTER_VALIDATE_INT)) {
			return "doit être un entier";
		}
		
		if ($type === 'string' && !is_string($valeur)) {
			return "doit être une chaîne de caractères";
		}
		
		if ($type === 'date' && !strtotime($valeur)) {
			return "date invalide";
		}
		
		if (isset($config['max'])) {
			if ($type === 'string' && strlen($valeur) > $config['max']) {
				return "ne doit pas dépasser {$config['max']} caractères";
			}
			
			if ($type === 'integer' && $valeur > $config['max']) {
				return "doit être inférieur ou égal à {$config['max']}";
			}
		}
		
		if (isset($config['min'])) {
			if ($type === 'integer' && $valeur < $config['min']) {
				return "doit être supérieur ou égal à {$config['min']}";
			}
		}
		
		return null;
	}