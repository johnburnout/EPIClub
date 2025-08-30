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
function creation_utilisateur(array $donnees, mysqli $db): array
{
    //global $db;

    $requiredFields = [
        'username' => 'string',
        'role' => 'string',
        'email' => 'string'
    ];

    foreach ($requiredFields as $field => $type) {
        if (!isset($donnees[$field])) {
            return ['id' => 0, 'success' => false, 'error' => "Champ obligatoire manquant: $field"];
        }

        if ($type === 'integer' && !is_numeric($donnees[$field])) {
            return ['id' => 0, 'success' => false, 'error' => "Type invalide pour $field (nombre attendu)"];
        }

        if ($type === 'string' && !is_scalar($donnees[$field])) {
            return ['id' => 0, 'success' => false, 'error' => "Type invalide pour $field (chaîne attendue)"];
        }
    }

    $utilisateur = $donnees['username'];
    $mdp = password_hash($donnees['username'], PASSWORD_DEFAULT);
    $email = $donnees['email'];
    $role = $donnees['role'];

    $sql1 = "INSERT INTO utilisateur (username, password, role, email) VALUES (?, ?, ?, ?)";
    $stmt1 = $db->prepare($sql1);
    $stmt1->bind_param("ssss", $utilisateur, $mdp, $role, $email);

    if (!$stmt1->execute()) {
        throw new Exception("Erreur MySQL: " . $stmt1->error);
    }

    $id = $db->insert_id;

    return ['id' => $id, 'success' => true, 'error' => ''];
}
