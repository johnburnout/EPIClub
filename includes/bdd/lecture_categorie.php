<?php

/**
 * Lit une fiche spécifique depuis la base de données
 * 
 * @param int $id Identifiant du fabricant à récupérer
 * @return array [
 *     'donnees' => array|null, // Données de la fiche ou null si non trouvée
 *     'success' => bool,       // Statut de l'opération
 *     'error' => string        // Message d'erreur le cas échéant
 * ]
 */
function lecture_categorie(int $id, ?mysqli $connection = null): array
{
    global $db;

    if ($id <= 0) {  // Changement à <= 0 car un ID doit être positif
        return ['donnees' => null, 'success' => false, 'error' => 'ID invalide'];
    }

    $sql = "SELECT 
            id,
            libelle
            FROM categorie 
            WHERE id = ?
            LIMIT 1";  // Changement de ORDER BY à LIMIT 1 car on cherche un enregistrement spécifique

    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        throw new Exception("Erreur d'exécution de la requête: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $donnees = $result->fetch_assoc();

    if (!$donnees) {
        return [
            'donnees' => null,
            'success' => false,
            'error' => 'Aucune catégorie trouvée'
        ];
    }

    return [
        'donnees' => $donnees,
        'success' => true,
        'error' => ''
    ];
}
