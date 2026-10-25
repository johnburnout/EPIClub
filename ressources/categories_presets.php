<?php

/**
 * Listes préparées de catégories d'équipements, proposées à l'étape
 * « Catégories » de l'installeur selon l'activité principale du club
 * (décision produit 2026-10-19).
 *
 * Chaque ligne : libellé, code (référencement automatique), est_epi,
 * duree_max_utilisation (durée de vie limitée par le fabricant).
 * L'utilisateur peut décocher les lignes non pertinentes ; tout reste
 * modifiable ensuite dans Administration → Catégories.
 *
 * Utilisé aussi par bin/installer.php (InstallationService), d'où son
 * emplacement hors de setup/.
 */

return [
    'Escalade' => [
        ['libelle' => 'Harnais',                  'code' => 'HAR', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Dégaine',                  'code' => 'DEG', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Assureur',                 'code' => 'ASS', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Mousqueton de sécurité',   'code' => 'MOU', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Corde à simple',           'code' => 'COR', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Corde de rappel / à double', 'code' => 'RAP', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Outillage',                'code' => 'OUT', 'est_epi' => 0, 'duree_max_utilisation' => 0],
        ['libelle' => 'Casque',                   'code' => 'CAS', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Cordelette',               'code' => 'FIC', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Anneau de Sangle',         'code' => 'ANN', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Corde pour SAE',           'code' => 'SAE', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Mousqueton simple',        'code' => 'MOS', 'est_epi' => 1, 'duree_max_utilisation' => 0],
    ],

    // Alpinisme : base commune escalade + matériel spécifique
    // (piolet, crampons, corde de randonnée/glacier, broche à glace,
    // ski de randonnée, chaussures de ski, ARVA) — décision 2026-10-19.
    'Alpinisme' => [
        ['libelle' => 'Harnais',                  'code' => 'HAR', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Dégaine',                  'code' => 'DEG', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Assureur',                 'code' => 'ASS', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Mousqueton de sécurité',   'code' => 'MOU', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Corde à simple',           'code' => 'COR', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Corde de rappel / à double', 'code' => 'RAP', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Outillage',                'code' => 'OUT', 'est_epi' => 0, 'duree_max_utilisation' => 0],
        ['libelle' => 'Casque',                   'code' => 'CAS', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Cordelette',               'code' => 'FIC', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Anneau de Sangle',         'code' => 'ANN', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Corde pour SAE',           'code' => 'SAE', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Mousqueton simple',        'code' => 'MOS', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Piolet',                   'code' => 'PIO', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Crampons',                 'code' => 'CRA', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Corde de randonnée / glacier', 'code' => 'CRG', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Broche à glace',           'code' => 'BRO', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Ski de randonnée',         'code' => 'SKI', 'est_epi' => 0, 'duree_max_utilisation' => 0],
        ['libelle' => 'Chaussures de ski',        'code' => 'CHA', 'est_epi' => 0, 'duree_max_utilisation' => 0],
        ['libelle' => 'ARVA',                     'code' => 'ARV', 'est_epi' => 0, 'duree_max_utilisation' => 0],
    ],

    // Spéléologie : technique verticale (bloqueurs, descendeur,
    // étriers) + combinaison et éclairage. Réglages EPI / durée max
    // à valider par le club.
    'Spéléologie' => [
        ['libelle' => 'Casque de spéléologie',    'code' => 'CAS', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Harnais de spéléologie',   'code' => 'HAR', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Bloqueur poignée',         'code' => 'BLO', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Bloqueur de poitrine',     'code' => 'BLP', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Descendeur (Stop)',        'code' => 'DES', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Étriers / pédale',         'code' => 'ETR', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Longe de sécurité',        'code' => 'LON', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Corde de spéléologie',     'code' => 'COR', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Cordelette',               'code' => 'FIC', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Anneau de sangle',         'code' => 'ANN', 'est_epi' => 1, 'duree_max_utilisation' => 1],
        ['libelle' => 'Mousqueton de sécurité',   'code' => 'MOU', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Mousqueton simple',        'code' => 'MOS', 'est_epi' => 1, 'duree_max_utilisation' => 0],
        ['libelle' => 'Éclairage (frontale)',     'code' => 'ECL', 'est_epi' => 0, 'duree_max_utilisation' => 0],
        ['libelle' => 'Combinaison de spéléologie', 'code' => 'COM', 'est_epi' => 0, 'duree_max_utilisation' => 0],
        ['libelle' => 'Sac de transport',         'code' => 'SAC', 'est_epi' => 0, 'duree_max_utilisation' => 0],
        ['libelle' => 'Outillage',                'code' => 'OUT', 'est_epi' => 0, 'duree_max_utilisation' => 0],
    ],
];
