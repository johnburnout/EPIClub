<?php

return [
    '/' => 'endpoints/index.php',
    '/tableau_de_bord' => 'endpoints/tableau_de_bord.php',
    '/creer_compte' => 'endpoints/utilisateur_register.php',
    '/mon_compte' => 'endpoints/utilisateur_detail.php',
    # '/se_connecter' => 'endpoints/utilisateur_login.php', // auto redirect to index by application if not authenticated
    '/se_deconnecter' => 'endpoints/utilisateur_logout.php',
    '/oubli_mot_de_passe' => 'endpoints/utilisateur_oubli_mdp.php',
    '/regenerer_mot_de_passe' => 'endpoints/utilisateur_reset_password.php',

    '/acquisitions' => 'endpoints/acquisition_liste.php',
    '/acquisition_creation' => 'endpoints/acquisition_creation.php',
    '/acquisition_effacer' => 'endpoints/acquisition_effacer.php',
    '/acquisition_terminer' => 'endpoints/acquisition_terminer.php',
    '/acquisition_verif' => 'endpoints/acquisition_verif.php',

    '/affectations' => 'endpoints/affectation_liste.php',
    '/affectation_detail' => 'endpoints/affectation_detail.php',
    '/affectation_effacer' => 'endpoints/affectation_effacer.php',

    '/affichages' => 'endpoints/affichage_liste.php',
    '/affichage_texte' => 'endpoints/affichage_texte.php',

    '/categories' => 'endpoints/categorie_liste.php',
    '/categorie_detail' => 'endpoints/categorie_detail.php',
    '/categorie_effacer' => 'endpoints/categorie_effacer.php',

    '/controles' => 'endpoints/controle_liste.php',
    '/controle_creation' => 'endpoints/controle_creation.php',
    '/controle_effacer' => 'endpoints/controle_effacer.php',
    '/controle_epi' => 'endpoints/controle_epi.php',
    '/controle_terminer' => 'endpoints/controle_terminer.php',

    '/download' => 'endpoints/download.php',

    '/epis' => 'endpoints/epi_liste.php',
    '/epi_detail' => 'endpoints/epi_detail.php',

    '/fabricants' => 'endpoints/fabricant_liste.php',
    '/fabricant_editer' => 'endpoints/fabricant_editer.php',
    '/fabricant_detail' => 'endpoints/fabricant_detail.php',
    '/fabricant_supprimer' => 'endpoints/fabricant_supprimer.php',

    '/fiche_creation' => 'endpoints/fiche_creation.php',
    '/fiche_effacer' => 'endpoints/fiche_effacer.php',

    '/journaux' => 'endpoints/journal_liste.php',

    '/admin/utilisateurs' => 'endpoints/admin_utilisateur_liste.php',
    '/admin/utilisateur_detail' => 'endpoints/admin_utilisateur_detail.php',
    '/admin/utilisateur_editer' => 'endpoints/admin_utilisateur_editer.php',
    # '/admin/utilisateur_supprimer' => 'endpoints/admin_utilisateur_supprimer.php',
];
