<?php
    
/**
* Met à jour une fiche de contrôle EPI dans la base de données
* 
* @param array $donnees Tableau associatif des données à mettre à jour contenant :
*               - 'libelle' : string nom du affectation (requis)
* @param int $id ID de l'utilisateur à modifier
* @param mysqli|null $connection Connexion MySQLi existante (optionnelle)
* 
* @return array [
*     'success' => bool,        // Statut de l'opération
*     'affected_rows' => int,   // Nombre de lignes affectées
*     'error' => string         // Message d'erreur le cas échéant
* ]
*/
function mise_a_jour_affectation(array $donnees, int $id, ?mysqli $connection = null): array {
    // 1. VALIDATION DES ENTREES
    if ($id <= 0) {
        return [
            'success' => false, 
            'affected_rows' => 0, 
            'error' => 'ID de fiche invalide'
        ];
    }
    // 2. VALIDATION DES CHAMPS OBLIGATOIRES
    $champsObligatoires = [
        'libelle' => 'string'
    ];
    
    foreach ($champsObligatoires as $champ => $type) {
        if (!isset($donnees[$champ])) {
            return [
                'success' => false,
                'affected_rows' => 0,
                'error' => "Champ obligatoire manquant: $champ"
            ];
        }
        
        // Validation des types
        $functionValidation = "is_$type";
        if (!$functionValidation($donnees[$champ])) {
            return [
                'success' => false,
                'affected_rows' => 0,
                'error' => "Type invalide pour $champ ($type attendu)"
            ];
        }
    }
    
    // 3. NETTOYAGE DES DONNEES
    $libelle = trim($donnees['libelle']);
    
    // 4. GESTION DE LA CONNEXION
    $shouldCloseConnection = false;
    try {
        if ($connection === null) {
            global $host, $username, $password, $dbname;
            
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $connection = new mysqli($host, $username, $password, $dbname);
            $connection->set_charset("utf8mb4");
            $shouldCloseConnection = true;
        }
        
        // 5. VERIFICATION DE L'UNICITE DU LIBELLE
        $checkSql = "SELECT id FROM affectation WHERE libelle = ? AND id != ?";
        $checkStmt = $connection->prepare($checkSql);
        
        if (!$checkStmt) {
            throw new Exception("Erreur de préparation de la requête de vérification: " . $connection->error);
        }
        
        $checkStmt->bind_param("si", $libelle, $id);
        $checkStmt->execute();
        $checkStmt->store_result();
        
        if ($checkStmt->num_rows > 0) {
            return [
                'success' => false,
                'affected_rows' => 0,
                'error' => 'Ce libellé existe déjà pour un autre affectation'
            ];
        }
        
        // 6. PREPARATION DE LA REQUETE DE MISE A JOUR
        $sql = "UPDATE affectation SET
                libelle = ?
                WHERE id = ?";
        
        $stmt = $connection->prepare($sql);
        if (!$stmt) {
            throw new Exception("Erreur de préparation de la requête: " . $connection->error);
        }
        
        // 7. EXECUTION DE LA REQUETE
        $stmt->bind_param(
            "si",
            $libelle,
            $id
        );
        
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        
        // 8. VERIFICATION DU RESULTAT
        if ($affectedRows === -1) {
            throw new Exception("Erreur lors de la mise à jour");
        }
        
        return [
            'success' => $affectedRows > 0,
            'affected_rows' => $affectedRows,
            'error' => $affectedRows > 0 ? '' : 'Aucune ligne mise à jour (ID peut-être inexistant)'
        ];
        
    } catch (mysqli_sql_exception $e) {
        error_log("Erreur MySQL lors de la mise à jour affectation $id: " . $e->getMessage());
        return [
            'success' => false,
            'affected_rows' => 0,
            'error' => 'Erreur de base de données'
        ];
    } catch (Exception $e) {
        error_log("Erreur lors de la mise à jour affectation $id: " . $e->getMessage());
        return [
            'success' => false,
            'affected_rows' => 0,
            'error' => $e->getMessage()
        ];
    } finally {
        // 9. FERMETURE PROPRE DE LA CONNEXION SI NECESSAIRE
        if ($shouldCloseConnection && $connection instanceof mysqli) {
            $connection->close();
        }
    }
}