<?php
    
    /**
    * Met à jour une fiche de contrôle EPI dans la base de données
    * 
    * @param array $donnees Tableau associatif des données à mettre à jour contenant :
    *               - 'vendeur' : string nom de la boutique (requis)
    *               - 'reference' : string reference de la facture (requis)
    *               - 'utilisateur' : string Identifiant de l'utilisateur	(requis)
	*               - 'date_facture' : string date d'achat	(requis)
    * @param int $id ID de la facture à modifier
    * @param mysqli|null $connection Connexion MySQLi existante (optionnelle)
    * 
    * @return array [
    *     'success' => bool,        // Statut de l'opération
    *     'affected_rows' => int,   // Nombre de lignes affectées
    *     'error' => string         // Message d'erreur le cas échéant
    * ]
    */
    function mise_a_jour_facture(array $donnees, int $id, ?mysqli $connection = null): array {
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
            'utilisateur' => 'string',
            'vendeur' => 'string',
            'reference' => 'string',
            'date_facture' => 'string'
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
        //$remarques = isset($donnees['remarques']) ? trim($donnees['remarques']) : null;
        
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
            // 5. PREPARATION DE LA REQUETE
            $sql = "UPDATE facture SET
            vendeur = ?,
            utilisateur = ?,
            date_facture = ?,
            reference = ?
            WHERE id = ?";
            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                throw new Exception("Erreur de préparation de la requête: " . $connection->error);
            }
            $vendeur = $donnees['vendeur'];
            $utilisateur = $donnees['utilisateur'];
            $date = date('Ymd',strtotime($donnees['date_facture']));
            $reference = $donnees["reference"];
            // 6. EXECUTION DE LA REQUETE
            $stmt->bind_param(
                "ssssi",
                $vendeur,
                $utilisateur,
                $date,
                $reference,
                $id
            );
            
            $stmt->execute();
            $affectedRows = $stmt->affected_rows;
            
            // 7. VERIFICATION DU RESULTAT
            if ($affectedRows === -1) {
                throw new Exception("Erreur lors de la mise à jour");
            }
            
            return [
                'success' => $affectedRows > 0,
                'affected_rows' => $affectedRows,
                'error' => $affectedRows > 0 ? '' : 'Aucune ligne mise à jour (ID peut-être inexistant)'
            ];
            
        } catch (mysqli_sql_exception $e) {
            error_log("Erreur MySQL lors de la mise à jour facture $id: " . $e->getMessage());
            return [
                'success' => false,
                'affected_rows' => 0,
                'error' => 'Erreur de base de données'
            ];
        } catch (Exception $e) {
            error_log("Erreur lors de la mise à jour facture $id: " . $e->getMessage());
            return [
                'success' => false,
                'affected_rows' => 0,
                'error' => $e->getMessage()
            ];
        } finally {
            // 8. FERMETURE PROPRE DE LA CONNEXION SI NECESSAIRE
            if ($shouldCloseConnection && $connection instanceof mysqli) {
                $connection->close();
            }
        }
    }