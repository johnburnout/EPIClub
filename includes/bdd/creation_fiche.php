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
    function creation_fiche(array $donnees, ?mysqli $connection = null): array {
        $requiredFields = [
            'reference' => 'string',
            'libelle' => 'string',
            'categorie_id' => 'integer',
            'fabricant_id' => 'integer',
            'facture_id' => 'integer'
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
            // Requête préparée
            $sql = "INSERT INTO matos (
                reference, libelle, categorie_id, fabricant_id, 
                photo, lieu_id, nb_elements_initial, nb_elements, 
                facture_id, date_debut, date_max, remarques
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                throw new Exception("Erreur de préparation de la requête");
            }
            
            // Préparation des variables pour bind_param
            $reference = $donnees['reference'];
            $libelle = $donnees['libelle'];
            $categorie_id = (int)$donnees['categorie_id'];
            $fabricant_id = (int)$donnees['fabricant_id'];
            $photo = $donnees['photo'] ?? null;
            $lieu_id = isset($donnees['lieu_id']) ? (int)$donnees['lieu_id'] : 1;
            $nb_elements_initial = isset($donnees['nb_elements_initial']) ? (int)$donnees['nb_elements_initial'] : 1;
            $nb_elements = isset($donnees['nb_elements']) ? (int)$donnees['nb_elements'] : 1;
            $facture_id = isset($_SESSION['facture_en_saisie']) ? intval($_SESSION['facture_en_saisie']) : null;
            $dateDebut = !empty($donnees['date_debut']) ? date('Y-m-d', strtotime($donnees['date_debut'])) : null;
            $dateMax = !empty($donnees['date_max']) ? date('Y-m-d', strtotime($donnees['date_max'])) : null;
            $remarques = $donnees['remarques'] ?? null;
            
            $stmt->bind_param(
                "ssiisiiiisss",
                $reference,
                $libelle,
                $categorie_id,
                $fabricant_id,
                $photo,
                $lieu_id,
                $nb_elements_initial,
                $nb_elements,
                $facture_id,
                $dateDebut,
                $dateMax,
                $remarques
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Erreur MySQL: " . $stmt->error);
            }
            
            $id = $connection->insert_id;    
            $stmt->close();
            
            $sql2 = "UPDATE matos SET facture_id = ? WHERE id = ?";
            $stmt2 = $connection->prepare($sql);
            if (!$stmt2) {
                throw new Exception("Erreur de préparation de la requête: " . $connection->error);
            }        
            $stmt2 = $connection->prepare($sql2);
            $stmt2->bind_param('ii', $facture_id, $id);
            $stmt2->execute();
            $affectedRows = $stmt2->affected_rows;    
            $stmt2->close();
            // 7. VÉRIFICATION DU RÉSULTAT
            if ($affectedRows === -1) {
                throw new Exception("Erreur lors de la mise à jour de facture_id");
            }
            return ['id' => $id, 'success' => true, 'error' => ''];
            
        } catch (mysqli_sql_exception $e) {
            error_log("Erreur MySQL: " . $e->getMessage());
            return ['id' => 0, 'success' => false, 'error' => 'Erreur de base de données'.$e->getMessage()];
        } catch (Exception $e) {
            error_log("Erreur lors de la création de la fiche: " . $e->getMessage());
            return ['id' => 0, 'success' => false, 'error' => $e->getMessage()];
        } finally {
            //if (isset($stmt)) $stmt->close();
            if (isset($connection)) $connection->close();
        }
    }
?>