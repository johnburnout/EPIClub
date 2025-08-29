<?php

/**
 * Met à jour une fiche de contrôle EPI dans la base de données
 * 
 * @param array $donnees Tableau associatif des données à mettre à jour contenant :
 *               - 'epi_controles' : array Liste des EPI contrôlés (requis)
 *               - 'remarques' : string Remarques optionnelles
 *               - 'utilisateur' : string Identifiant de l'utilisateur (requis)
 * @param int $id ID de la fiche à modifier
 * @return array [
 *     'success' => bool,        // Statut de l'opération
 *     'affected_rows' => int,   // Nombre de lignes affectées
 *     'error' => string         // Message d'erreur le cas échéant
 * ]
 */
function mise_a_jour_controle(array $donnees, int $id): array
{
    global $db;

    if ($id <= 0) {
        return [
            'success' => false,
            'affected_rows' => 0,
            'error' => 'ID de fiche invalide'
        ];
    }

    $champsObligatoires = [
        'utilisateur' => 'string',
        'epi_controles' => 'string'
    ];

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

    $remarques = isset($donnees['remarques']) ? trim($donnees['remarques']) : null;

    $sql = "UPDATE verification SET
            epi_controles = ?,
            remarques = ?,
            utilisateur = ?
            WHERE id = ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        "sssi",
        $donnees['epi_controles'],
        $remarques,
        $donnees['utilisateur'],
        $id
    );
    $stmt->execute();

    $affectedRows = $stmt->affected_rows;

    if ($affectedRows === -1) {
        throw new Exception("Erreur lors de la mise à jour");
    }

    return [
        'success' => $affectedRows > 0,
        'affected_rows' => $affectedRows,
        'error' => $affectedRows > 0 ? '' : 'Aucune ligne mise à jour (ID peut-être inexistant)'
    ];
}
