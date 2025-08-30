<?php

/**
 * Met à jour une fiche de contrôle EPI dans la base de données
 * 
 * @param array $donnees Tableau associatif des données à mettre à jour contenant :
 *               - 'libelle' : string nom du fabricant (requis)
 * @param int $id ID de l'utilisateur à modifier
 * @return array [
 *     'success' => bool,        // Statut de l'opération
 *     'affected_rows' => int,   // Nombre de lignes affectées
 *     'error' => string         // Message d'erreur le cas échéant
 * ]
 */
function mise_a_jour_fabricant(array $donnees, int $id, mysqli $db): array
{
    //global $db;

    if ($id <= 0) {
        return [
            'success' => false,
            'affected_rows' => 0,
            'error' => 'ID de fiche invalide'
        ];
    }

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

        $functionValidation = "is_$type";
        if (!$functionValidation($donnees[$champ])) {
            return [
                'success' => false,
                'affected_rows' => 0,
                'error' => "Type invalide pour $champ ($type attendu)"
            ];
        }
    }

    $libelle = trim($donnees['libelle']);

    $checkSql = "SELECT id FROM fabricant WHERE libelle = ? AND id != ?";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->bind_param("si", $libelle, $id);
    $checkStmt->execute();
    $checkStmt->store_result();

    if ($checkStmt->num_rows > 0) {
        return [
            'success' => false,
            'affected_rows' => 0,
            'error' => 'Ce libellé existe déjà pour un autre fabricant'
        ];
    }

    $sql = "UPDATE fabricant SET
                libelle = ?
                WHERE id = ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        "si",
        $libelle,
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
