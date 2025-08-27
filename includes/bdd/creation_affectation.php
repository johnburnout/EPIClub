<?php
    /**
    * Crée une nouvelle fiche dans la base de données
    * 
    * @param array $donnees Tableau associatif contenant les données de la fiche
    * @return array [
    *     'id' => int,        // ID de la fiche créée (0 en cas d'échec)
    *     'success' => bool,  // Statut de l'opération
    *     'error' => string   // Message d'erreur le cas échéant
    * ]
    */
    function creation_affectation(array $donnees, ?mysqli $connection = null): array {
        $requiredFields = [
            'libelle' => 'string'
        ];
        
        // 1. VALIDATION DE L'ENTRÉE
        foreach ($requiredFields as $field => $type) {
            if (!isset($donnees[$field])) {
                return ['id' => 0, 'success' => false, 'error' => "Champ obligatoire manquant: $field"];
            }
            
            // Validation du type
            if ($type === 'integer' && !is_numeric($donnees[$field])) {
                return ['id' => 0, 'success' => false, 'error' => "Type invalide pour $field (nombre attendu)"];
            }
            if ($type === 'string' && !is_scalar($donnees[$field])) {
                return ['id' => 0, 'success' => false, 'error' => "Type invalide pour $field (chaîne attendue)"];
            }
        } // CORRECTION: Cette accolade fermante manquait
        
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
            
            // Première requête: insertion
            $sql1 = "INSERT INTO affectation (libelle) VALUES (?)";
            $stmt1 = $connection->prepare($sql1);
            if (!$stmt1) {
                throw new Exception("Erreur de préparation de la première requête");
            }
            
            $affectation = $donnees['libelle'];
            
            $stmt1->bind_param("s", $affectation);
            if (!$stmt1->execute()) {
                throw new Exception("Erreur MySQL: " . $stmt1->error);
            }
            
            $id = $connection->insert_id;
            $stmt1->close();
            
            return ['id' => $id, 'success' => true, 'error' => ''];
            
        } catch (mysqli_sql_exception $e) {
            error_log("Erreur MySQL: " . $e->getMessage());
            return ['id' => 0, 'success' => false, 'error' => 'Erreur de base de données: '. $e->getMessage()];
        } catch (Exception $e) {
            error_log("Erreur: " . $e->getMessage());
            return ['id' => 0, 'success' => false, 'error' => $e->getMessage()];
        } finally {
            if (isset($shouldCloseConnection) && $shouldCloseConnection && $connection instanceof mysqli) {
                $connection->close();
            }
        }
    }
?>