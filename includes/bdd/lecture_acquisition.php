<?php

/**
 * Lit une fiche spécifique depuis la base de données
 * 
 * @param int $id Identifiant de la fiche à récupérer
 * @param string $utilisateur Nom de l'utilisateur à vérifier
 * @return array [
 *     'donnees' => array|null, // Données de la fiche ou null si non trouvée
 *     'success' => bool,       // Statut de l'opération
 *     'error' => string        // Message d'erreur le cas échéant
 * ]
 */
function lecture_acquisition(int $id, string $utilisateur): array
{
    global $db;

    if ($id <= 0) {  // Changement à <= 0 car un ID doit être positif
        return ['donnees' => null, 'success' => false, 'error' => 'ID invalide'];
    }

    $sql = "SELECT 
            id,
            utilisateur, 
            date_acquisition, 
            vendeur, 
            libelle,
            en_saisie,
            reference,
            fichier
            FROM acquisition 
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
            'error' => 'Aucune acquisition en saisie trouvée'
        ];
    }

    if (!empty($donnees['date_acquisition'])) {
        $donnees['date_acquisition'] = date('Y-m-d', strtotime($donnees['date_acquisition']));
    }

    return [
        'donnees' => $donnees,
        'success' => true,
        'error' => ''
    ];
}
