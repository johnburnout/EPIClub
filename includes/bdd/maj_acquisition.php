<?php

/**
 * Met à jour une fiche de contrôle EPI dans la base de données
 * 
 * @param array $donnees Tableau associatif des données à mettre à jour contenant :
 *               - 'vendeur' : string nom de la boutique (requis)
 *               - 'reference' : string reference de l'acquisition (requis)
 *               - 'utilisateur' : string Identifiant de l'utilisateur	(requis)
 *               - 'date_acquisition' : string date d'achat	(requis)
 * @param int $id ID de l'acquisition à modifier
 * @param mysqli|null $connection Connexion MySQLi existante (optionnelle)
 * 
 * @return array [
 *     'success' => bool,        // Statut de l'opération
 *     'affected_rows' => int,   // Nombre de lignes affectées
 *     'error' => string         // Message d'erreur le cas échéant
 * ]
 */
function mise_a_jour_acquisition(array $donnees, int $id, ?mysqli $connection = null): array
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
        'vendeur' => 'string',
        'reference' => 'string',
        'date_acquisition' => 'string'
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

    $vendeur = $donnees['vendeur'];
    $utilisateur = $donnees['utilisateur'];
    $date = date('Ymd', strtotime($donnees['date_acquisition']));
    $reference = $donnees["reference"];

    $sql = "UPDATE acquisition SET
            vendeur = ?,
            utilisateur = ?,
            date_acquisition = ?,
            reference = ?
            WHERE id = ?";
    $stmt = $db->prepare($sql);
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

    if ($affectedRows === -1) {
        throw new Exception("Erreur lors de la mise à jour");
    }

    return [
        'success' => $affectedRows > 0,
        'affected_rows' => $affectedRows,
        'error' => $affectedRows > 0 ? '' : 'Aucune ligne mise à jour (ID peut-être inexistant)'
    ];
}
