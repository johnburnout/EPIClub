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
    * @param mysqli|null $connection Connexion MySQLi existante (optionnelle)
    * 
    * @return array [
    *     'success' => bool,        // Statut de l'opération
    *     'affected_rows' => int,   // Nombre de lignes affectées
    *     'error' => string         // Message d'erreur le cas échéant
    * ]
    */
	function mise_a_jour_utilisateur(array $donnees, int $id, ?mysqli $connection = null): array {
	    // 1. VALIDATION DES ENTREES
	    if ($id <= 0) {
	        return [
	            'success' => false, 
	            'affected_rows' => 0, 
	            'error' => 'ID de fiche invalide'
	        ];
	    }
	
	    // 2. VÉRIFICATION DE L'UNICITÉ DU USERNAME (nouvelle section)
try {
    if ($connection === null) {
        global $host, $username, $password, $dbname;
        $connection = new mysqli($host, $username, $password, $dbname);
        $connection->set_charset("utf8mb4");
        $shouldCloseConnection = true;
    }

    // Vérifie si le username existe déjà pour un autre utilisateur
    $checkUsernameStmt = $connection->prepare("SELECT id FROM utilisateur WHERE username = ? AND id != ?");
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

    // Vérifie si l'email existe déjà pour un autre utilisateur
    $checkEmailStmt = $connection->prepare("SELECT id FROM utilisateur WHERE email = ? AND id != ?");
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

} catch (Exception $e) {
    error_log("Erreur lors de la vérification de l'unicité: " . $e->getMessage());
    return [
        'success' => false,
        'affected_rows' => 0,
        'error' => 'Erreur lors de la vérification de l\'unicité des informations'
    ];
}
	
	    // 3. VALIDATION DES CHAMPS OBLIGATOIRES (section existante)
	    $champsObligatoires = [
	        'username' => 'string',
	        'email' => 'string',
	        'role'=> 'string',
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
	
	    // 4. EXÉCUTION DE LA MISE À JOUR (section existante)
	    try {
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
	
	        $stmt = $connection->prepare($sql);
	        if (!$stmt) {
	            throw new Exception("Erreur de préparation de la requête: " . $connection->error);
	        }
			$password_hash = password_hash($donnees['password'], PASSWORD_DEFAULT) ?? '';
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
	        
	    } catch (mysqli_sql_exception $e) {
	        error_log("Erreur MySQL lors de la mise à jour utilisateur $id: " . $e->getMessage());
	        return [
	            'success' => false,
	            'affected_rows' => 0,
	            'error' => 'Erreur de base de données'
	        ];
	    } finally {
	        if (isset($shouldCloseConnection) && $connection instanceof mysqli) {
	            $connection->close();
	        }
	    }
	}