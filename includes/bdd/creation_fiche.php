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
function creation_fiche(array $donnees, mysqli $db): array
{
    //global $db;

    $requiredFields = [
        'reference' => 'string',
        'libelle' => 'string',
        'categorie_id' => 'integer',
        'fabricant_id' => 'integer',
        'acquisition_id' => 'integer'
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

    $reference = $donnees['reference'];
    $libelle = $donnees['libelle'];
    $categorie_id = (int)$donnees['categorie_id'];
    $fabricant_id = (int)$donnees['fabricant_id'];
    $photo = $donnees['photo'] ?? null;
    $affectation_id = isset($donnees['affectation_id']) ? (int)$donnees['affectation_id'] : 2;
    $nb_elements_initial = isset($donnees['nb_elements_initial']) ? (int)$donnees['nb_elements_initial'] : 1;
    $nb_elements = isset($donnees['nb_elements']) ? (int)$donnees['nb_elements'] : 1;
    $acquisition_id = isset($_SESSION['acquisition_en_saisie']) ? intval($_SESSION['acquisition_en_saisie']) : null;
    $dateDebut = !empty($donnees['date_debut']) ? date('Y-m-d', strtotime($donnees['date_debut'])) : null;
    $dateMax = !empty($donnees['date_max']) ? date('Y-m-d', strtotime($donnees['date_max'])) : null;
    $remarques = $donnees['remarques'] ?? null;

    $sql = "INSERT INTO matos (
                reference, libelle, categorie_id, fabricant_id, 
                photo, affectation_id, nb_elements_initial, nb_elements, 
                acquisition_id, date_debut, date_max, remarques
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        "ssiisiiiisss",
        $reference,
        $libelle,
        $categorie_id,
        $fabricant_id,
        $photo,
        $affectation_id,
        $nb_elements_initial,
        $nb_elements,
        $acquisition_id,
        $dateDebut,
        $dateMax,
        $remarques
    );

    if (!$stmt->execute()) {
        throw new Exception("Erreur MySQL: " . $stmt->error);
    }

    $id = $db->insert_id;

    $sql2 = "UPDATE matos SET acquisition_id = ? WHERE id = ?";
    $stmt2 = $db->prepare($sql);
    $stmt2 = $db->prepare($sql2);
    $stmt2->bind_param('ii', $acquisition_id, $id);
    $stmt2->execute();

    $affectedRows = $stmt2->affected_rows;

    if ($affectedRows === -1) {
        throw new Exception("Erreur lors de la mise à jour de acquisition_id");
    }

    return ['id' => $id, 'success' => true, 'error' => ''];
}
