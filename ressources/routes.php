<?php

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Déclaration des routes de l'application.
 *
 * ⚠️ IMPORTANT
 * ------------
 * Ce fichier ne gère PAS les permissions. Il se contente de mapper une URL
 * vers un contrôleur et une action. Le contrôle d'accès est réalisé dans
 * chaque méthode de contrôleur via :
 *
 *     $this->deniAccessUnlessGranted('ROLE_XXX');
 *
 * Les rôles indiqués en commentaire ci-dessous sont donc purement
 * documentaires. Ils indiquent le rôle minimum requis pour accéder à la
 * route. Grâce à la hiérarchie définie dans Session::isGranted() :
 *
 *     USER < CONTROLLEUR < ADMIN < SUPER_ADMIN
 *
 * un utilisateur disposant d'un rôle supérieur satisfait automatiquement
 * les routes des rôles inférieurs.
 */

$routes = new RouteCollection();

// =====================================================================
// UTILISATEUR — Tableau de bord
// =====================================================================

// Public : redirige vers /se_connecter si non authentifié, sinon vers /tableau_de_bord
$routes->add('index', new Route('/', ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'index']));

// ROLE_USER : page d'accueil des utilisateurs connectés
$routes->add('dashboard', new Route('/tableau_de_bord', ['_controller' => 'Epiclub\\Controller\\TableauDeBordController', 'action' => 'index']));

// ROLE_USER : fermeture de la notification « EPI à renouveler »
// (jusqu'à la prochaine connexion)
$routes->add('dashboard_masquer_alerte', new Route(
    '/tableau_de_bord/masquer-alerte',
    ['_controller' => 'Epiclub\\Controller\\TableauDeBordController', 'action' => 'masquerAlerte'],
    [], [], '', [], ['POST']
));

// ROLE_ADMIN : personnalisation du tableau de bord (logo, photo de
// fond, horizon de la notification de renouvellement)
$routes->add('dashboard_reglages', new Route(
    '/admin/tableau-de-bord',
    ['_controller' => 'Epiclub\\Controller\\TableauDeBordController', 'action' => 'reglages']
));


// =====================================================================
// ROUTES SYSTEME (Configuration SMTP)
// =====================================================================
// ⚠️ Réservées à ROLE_SUPER_ADMIN (vérification dans IndexController)

$routes->add('system_settings', new Route(
    '/admin/system-settings',
    ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'systemSettings']
));

// [#97 - Dette 1] Route canonique renommée : l'action gère SMTP ET
// ROOT_URL. L'ancienne /update-smtp est conservée en alias (même
// action, POST) pour ne pas casser les bookmarks — une redirection
// perdrait le corps POST.

$routes->add('update_system_settings', new Route(
    '/update-system-settings',
    ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'updateSystemSettings'],
    [], [], '', [], ['POST']
));

// Alias historique (Dette 1) — même action que /update-system-settings.
$routes->add('update_smtp', new Route(
    '/update-smtp',
    ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'updateSystemSettings'],
    [], [], '', [], ['POST']
));

$routes->add('test_mail', new Route(
    '/test-mail',
    ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'testMail'],
    [], [], '', [], ['POST']
));


// =====================================================================
// AUTHENTIFICATION — Routes publiques
// =====================================================================

$routes->add('login',  new Route('/se_connecter',    ['_controller' => 'Epiclub\\Controller\\AppUserAuthController', 'action' => 'login']));
$routes->add('logout', new Route('/se_deconnecter',  ['_controller' => 'Epiclub\\Controller\\AppUserAuthController', 'action' => 'logout']));

// ROLE_USER : compte utilisateur (création, consultation, mot de passe oublié)
$routes->add('forgot_password',         new Route('/mot_de_passe_oublie',                 ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'forgotPassword']));
$routes->add('forgot_password_confirm', new Route('/mot_de_passe_oublie/confirmation',    ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'forgotPasswordConfirm']));
$routes->add('reset_password',          new Route('/regenerer_mot_de_passe',              ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'resetPassword']));


// =====================================================================
// EQUIPEMENTS
// =====================================================================

// ROLE_USER : consultation, export, étiquettes, fiche PDF
$routes->add('equipement_list',       new Route('/equipements',                          ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'list']));
$routes->add('equipement_list_excel', new Route('/equipements/excel-liste',              ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'listExcel']));
$routes->add('equipement_etiquettes', new Route('/equipements/etiquettes',               ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'etiquettesPdf']));
$routes->add('equipement_pdf',        new Route('/equipements/equipement-pdf-{id}',      ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'pdf']));
$routes->add('equipement_show',       new Route('/equipements/equipement-{id}',          ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'show']));

// ROLE_ADMIN : modification groupée des équipements cochés dans la liste
// (GET : formulaire, POST : application)
$routes->add('equipement_modification_groupee', new Route(
    '/equipements/modification-groupee',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'modificationGroupee'],
    [], [], '', [], ['GET', 'POST']
));

// Modification : ROLE_ADMIN (ROLE_CONTROLLEUR : emplacement seul) ; suppression : ROLE_ADMIN
$routes->add('equipement_edit', new Route(
    '/equipements/equipement_modification-{id}',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'edit']
));
$routes->add('equipement_delete', new Route(
    '/equipements/equipement_supprimer-{id}',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'delete'],
    [], [], '', [], ['POST']
));

// ROLE_SUPER_ADMIN : vérification d'unicité à la volée (AJAX) de la
// référence saisie dans le formulaire de modification
$routes->add('equipement_reference_disponible', new Route(
    '/equipements/reference-disponible',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'referenceDisponible'],
    [], [], '', [], ['GET']
));

// ROLE_ADMIN : dissociation d'un lot (formulaire de modification) —
// références proposées (AJAX) puis dissociation (POST)
$routes->add('equipement_dissociation_references', new Route(
    '/equipements/dissociation-references',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'referencesDissociation'],
    [], [], '', [], ['GET']
));
$routes->add('equipement_dissocier', new Route(
    '/equipements/equipement_dissocier-{id}',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'dissocier'],
    [], [], '', [], ['POST']
));

// ROLE_SUPER_ADMIN : référence proposée (AJAX) pour la catégorie saisie
// dans le formulaire de modification (année d'achat de l'équipement)
$routes->add('equipement_reference_suggestion', new Route(
    '/equipements/reference-suggestion',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'referenceSuggestion'],
    [], [], '', [], ['GET']
));


// =====================================================================
// JOURNAUX
// =====================================================================
// ROLE_USER : consultation et export PDF

$routes->add('journal_list', new Route('/journaux',          ['_controller' => 'Epiclub\\Controller\\JournalController', 'action' => 'index']));
$routes->add('journal_show', new Route('/journaux/{id}',     ['_controller' => 'Epiclub\\Controller\\JournalController', 'action' => 'voir']));
$routes->add('journal_pdf',  new Route('/journaux/pdf/{id}', ['_controller' => 'Epiclub\\Controller\\JournalController', 'action' => 'pdf']));


// =====================================================================
// CONTROLES
// =====================================================================
// ROLE_CONTROLLEUR : création, édition et clôture des contrôles

$routes->add('controle_list', new Route(
    '/admin/controles',
    ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'list']
));
$routes->add('controle_edit', new Route(
    '/admin/controles/edit/{id}',
    ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'edit']
));
$routes->add('controle_creer', new Route(
    '/admin/controles/creer',
    ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'create'],
    [], [], '', [], ['POST']
));
$routes->add('controle_add_equipement', new Route(
    '/admin/controles/add-equipement/{controle_id}',
    ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'addEquipement'],
    [], [], '', [], ['POST']
));
$routes->add('controle_update_ligne', new Route(
    '/admin/controles/update-ligne/{id}',
    ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'updateLigne']
));
$routes->add('controle_cloturer', new Route(
    '/admin/controles/cloturer/{id}',
    ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'cloturer'],
    [], [], '', [], ['POST']
));
$routes->add('controle_delete', new Route(
    '/admin/controles/supprimer/{id}',
    ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'delete'],
    [], [], '', [], ['POST']
));

// Scan QR dans la page (caméra), sans ouvrir une nouvelle page à chaque
// scan : ajout en AJAX au contrôle ouvert (décision produit 2026-10-19).
$routes->add('controle_scan_page', new Route(
    '/controles/scanner',
    ['_controller' => 'Epiclub\\Controller\\ControleScanController', 'action' => 'page']
));
$routes->add('controle_scan_ajouter', new Route(
    '/controles/scanner/ajouter',
    ['_controller' => 'Epiclub\\Controller\\ControleScanController', 'action' => 'ajouter'],
    [], [], '', [], ['POST']
));


// =====================================================================
// PRÊT DE MATÉRIEL (module facultatif — migration 016)
// =====================================================================
// ROLE_CONTROLLEUR + module activé (club.pret_actif), vérifiés dans
// PretController. Ordre : routes fixes avant /prets/pret-{id}.
$routes->add('pret_list', new Route(
    '/prets',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'list']
));
$routes->add('pret_create', new Route(
    '/prets/nouveau',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'create'],
    [], [], '', [], ['GET', 'POST']
));
// Ajout de matériel à un prêt en saisie (fiche du prêt, liste ou fiche
// des équipements) : pret_id + ids[] / equipement_id
$routes->add('pret_ajouter', new Route(
    '/prets/ajouter',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'ajouter'],
    [], [], '', [], ['POST']
));
$routes->add('pret_scan_ajouter', new Route(
    '/prets/scanner/ajouter',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'scanAjouter'],
    [], [], '', [], ['POST']
));
$routes->add('pret_retirer', new Route(
    '/prets/ligne-{id}/retirer',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'retirer'],
    ['id' => '\d+'], [], '', [], ['POST']
));
$routes->add('pret_scanner', new Route(
    '/prets/pret-{id}/scanner',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'scanner'],
    ['id' => '\d+']
));
$routes->add('pret_valider', new Route(
    '/prets/pret-{id}/valider',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'valider'],
    ['id' => '\d+'], [], '', [], ['GET', 'POST']
));
$routes->add('pret_terminer', new Route(
    '/prets/pret-{id}/terminer',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'terminer'],
    ['id' => '\d+'], [], '', [], ['POST']
));
$routes->add('pret_retour', new Route(
    '/prets/pret-{id}/retour',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'retour'],
    ['id' => '\d+'], [], '', [], ['POST']
));
$routes->add('pret_supprimer', new Route(
    '/prets/pret-{id}/supprimer',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'supprimer'],
    ['id' => '\d+'], [], '', [], ['POST']
));
$routes->add('pret_pdf', new Route(
    '/prets/pret-{id}/pdf',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'pdf'],
    ['id' => '\d+']
));
$routes->add('pret_show', new Route(
    '/prets/pret-{id}',
    ['_controller' => 'Epiclub\\Controller\\PretController', 'action' => 'show'],
    ['id' => '\d+'], [], '', [], ['GET', 'POST']
));
// =====================================================================
// ADMINISTRATEUR — Réglages du club
// =====================================================================
// ⚠️ Réservé à ROLE_SUPER_ADMIN (vérification dans ClubController)

$routes->add('club_show', new Route(
    '/admin/club',
    ['_controller' => 'Epiclub\\Controller\\ClubController', 'action' => 'show']
));


// =====================================================================
// REFERENCEMENT AUTOMATIQUE
// =====================================================================
// ROLE_ADMIN : modèle global de référence (club.ref_modele)

$routes->add('reference_settings', new Route(
    '/admin/references',
    ['_controller' => 'Epiclub\\Controller\\ReferenceController', 'action' => 'edit']
));


// =====================================================================
// CATEGORIE
// =====================================================================
// ROLE_ADMIN (list/show : ROLE_USER)

// Ordre : DELETE avant SHOW pour éviter les collisions d'URL
$routes->add('categorie_list', new Route(
    '/admin/categories',
    ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'list']
));
$routes->add('categorie_create', new Route(
    '/admin/categories/nouvelle',
    ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'edit']
));
$routes->add('categorie_update', new Route(
    '/admin/categories/categorie_modification-{id}',
    ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'edit']
));
$routes->add('categorie_delete', new Route(
    '/admin/categories/supprimer',
    ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'delete'],
    [], [], '', [], ['POST']
));
$routes->add('categorie_show', new Route(
    '/admin/categories/categorie-{id}',
    ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'show']
));


// =====================================================================
// ACQUISITION
// =====================================================================
// ROLE_USER (list/show) et ROLE_ADMIN (create/update/delete/valider)

$routes->add('acquisition_list', new Route(
    '/admin/acquisitions',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'list']
));
$routes->add('acquisition_create', new Route(
    '/admin/acquisitions/nouvelle',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'create']
));
$routes->add('acquisition_import', new Route(
    '/admin/acquisitions/import',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionImportController', 'action' => 'import']
));
$routes->add('acquisition_edit', new Route(
    '/admin/acquisitions/acquisition_modification-{id}',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'update']
));
$routes->add('acquisition_show', new Route(
    '/admin/acquisitions/acquisition-{id}',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'show']
));
$routes->add('acquisition_delete', new Route(
    '/admin/acquisitions/acquisition_supprimer-{id}',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'delete'],
    [], [], '', [], ['POST']
));
$routes->add('acquisition_valider', new Route(
    '/admin/acquisitions/valider/{id}',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'valider'],
    [], [], '', [], ['POST']
));
// ROLE_ADMIN : proposition de référence (AJAX) à la saisie d'une ligne
$routes->add('acquisition_reference_suggestion', new Route(
    '/admin/acquisitions/reference-suggestion',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'suggestionReference']
));
$routes->add('acquisition_ligne_edit', new Route(
    '/admin/acquisitions/ligne_modification-{id}',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionLineController', 'action' => 'modifyLine']
));
$routes->add('acquisition_ligne_delete', new Route(
    '/admin/acquisitions/ligne_supprimer-{id}',
    ['_controller' => 'Epiclub\\Controller\\AcquisitionLineController', 'action' => 'deleteLine'],
    [], [], '', [], ['POST']
));


// =====================================================================
// UPLOADS — Service des fichiers téléversés
// =====================================================================
// Le contrôleur UploadController gère lui-même la vérification d'accès.

$routes->add('uploads', new Route(
    '/uploads/{path}',
    ['_controller' => 'Epiclub\\Controller\\UploadController', 'action' => 'serve'],
    ['path' => '.+']
));


// =====================================================================
// FOURNISSEUR
// =====================================================================
// ROLE_ADMIN

// Ordre : DELETE avant SHOW pour éviter les collisions d'URL
$routes->add('fournisseur_list', new Route(
    '/admin/fournisseurs',
    ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'list']
));
$routes->add('fournisseur_create', new Route(
    '/admin/fournisseurs/nouveau',
    ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'edit']
));
$routes->add('fournisseur_update', new Route(
    '/admin/fournisseurs/fournisseur_modification-{id}',
    ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'edit']
));
$routes->add('fournisseur_delete', new Route(
    '/admin/fournisseurs/supprimer',
    ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'delete'],
    [], [], '', [], ['POST']
));
$routes->add('fournisseur_show', new Route(
    '/admin/fournisseurs/fournisseur-{id}',
    ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'show']
));


// =====================================================================
// UTILISATEUR
// =====================================================================
// ROLE_ADMIN (mais règles fines dans UtilisateurController pour
// empêcher un ADMIN de gérer un autre ADMIN ou un SUPER_ADMIN)

$routes->add('utilisateur_list', new Route(
    '/admin/utilisateurs',
    ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'list']
));
$routes->add('utilisateur_create', new Route(
    '/admin/utilisateurs/nouveau',
    ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'edit']
));
# @deprecated $routes->add('utilisateur_update', new Route('/admin/utilisateurs/utilisateur_modification-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'edit']));
$routes->add('utilisateur_delete', new Route(
    '/admin/utilisateurs/utilisateur_supprimer-{id}',
    ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'delete'],
    [], [], '', [], ['POST']
));
$routes->add('utilisateur_show', new Route(
    '/admin/utilisateurs/utilisateur-{id}',
    ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'show']
));


// =====================================================================
// EMPLACEMENT
// =====================================================================
// ROLE_ADMIN (list/show : ROLE_USER)

// Ordre : DELETE avant SHOW pour éviter les collisions d'URL
$routes->add('emplacement_list', new Route(
    '/admin/emplacements',
    ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'list']
));
$routes->add('emplacement_create', new Route(
    '/admin/emplacements/nouveau',
    ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'edit']
));
$routes->add('emplacement_update', new Route(
    '/admin/emplacements/emplacement_modification-{id}',
    ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'edit']
));
$routes->add('emplacement_delete', new Route(
    '/admin/emplacements/supprimer',
    ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'delete'],
    [], [], '', [], ['POST']
));
$routes->add('emplacement_show', new Route(
    '/admin/emplacements/emplacement-{id}',
    ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'show']
));


// =====================================================================
// QR CODE
// =====================================================================
// Le QrRedirectController gère lui-même la logique d'accès.
// La route /qr/{id} est publique par nature (scan depuis un téléphone).

$routes->add('qr_redirect', new Route('/qr/{id}',                    ['_controller' => 'Epiclub\\Controller\\QrRedirectController', 'action' => 'redirect']));
$routes->add('qr_choice',   new Route('/qr/choix/{id}',              ['_controller' => 'Epiclub\\Controller\\QrRedirectController', 'action' => 'choicePage']));
$routes->add('qr_generate', new Route('/qr/generate/{id}',           ['_controller' => 'Epiclub\\Controller\\QrRedirectController', 'action' => 'generateQr']));
$routes->add('qr_download', new Route('/qr/download/{id}',           ['_controller' => 'Epiclub\\Controller\\QrRedirectController', 'action' => 'downloadQr']));
$routes->add('qr_view',     new Route('/qr/view/{filename}',         ['_controller' => 'Epiclub\\Controller\\QrRedirectController', 'action' => 'viewQr']));
$routes->add('qr_save',     new Route('/qr/save/{id}',               ['_controller' => 'Epiclub\\Controller\\QrRedirectController', 'action' => 'saveQr']));


// =====================================================================
// MISE A JOUR DE L'APPLICATION
// =====================================================================
// ⚠️ Réservées à ROLE_SUPER_ADMIN (vérification dans AppUpdateController)

$routes->add('admin_update', new Route(
    '/admin/update',
    ['_controller' => 'Epiclub\\Controller\\AppUpdateController', 'action' => 'index']
));
$routes->add('admin_update_perform', new Route(
    '/admin/update/perform',
    ['_controller' => 'Epiclub\\Controller\\AppUpdateController', 'action' => 'perform'],
    [], [], '', [], ['POST']
));
$routes->add('admin_update_cleanup', new Route(
    '/admin/update/cleanup',
    ['_controller' => 'Epiclub\\Controller\\AppUpdateController', 'action' => 'cleanup'],
    [], [], '', [], ['POST']
));


// =====================================================================
// SAUVEGARDE / RESTAURATION (base + fichiers)
// =====================================================================
// ⚠️ Réservées à ROLE_SUPER_ADMIN (vérification dans SauvegardeController)

$routes->add('sauvegarde', new Route(
    '/admin/sauvegarde',
    ['_controller' => 'Epiclub\\Controller\\SauvegardeController', 'action' => 'index']
));
$routes->add('sauvegarde_exporter', new Route(
    '/admin/sauvegarde/exporter',
    ['_controller' => 'Epiclub\\Controller\\SauvegardeController', 'action' => 'exporter'],
    [], [], '', [], ['POST']
));
$routes->add('sauvegarde_televerser', new Route(
    '/admin/sauvegarde/televerser',
    ['_controller' => 'Epiclub\\Controller\\SauvegardeController', 'action' => 'televerser'],
    [], [], '', [], ['POST']
));
$routes->add('sauvegarde_verifier', new Route(
    '/admin/sauvegarde/verifier/{nom}',
    ['_controller' => 'Epiclub\\Controller\\SauvegardeController', 'action' => 'verifier'],
    ['nom' => '[A-Za-z0-9._-]+']
));
$routes->add('sauvegarde_restaurer', new Route(
    '/admin/sauvegarde/restaurer/{nom}',
    ['_controller' => 'Epiclub\\Controller\\SauvegardeController', 'action' => 'restaurer'],
    ['nom' => '[A-Za-z0-9._-]+'], [], '', [], ['POST']
));
$routes->add('sauvegarde_telecharger', new Route(
    '/admin/sauvegarde/telecharger/{nom}',
    ['_controller' => 'Epiclub\\Controller\\SauvegardeController', 'action' => 'telecharger'],
    ['nom' => '[A-Za-z0-9._-]+']
));
$routes->add('sauvegarde_supprimer', new Route(
    '/admin/sauvegarde/supprimer/{nom}',
    ['_controller' => 'Epiclub\\Controller\\SauvegardeController', 'action' => 'supprimer'],
    ['nom' => '[A-Za-z0-9._-]+'], [], '', [], ['POST']
));


// =====================================================================
// QR Code API (JSON)
// =====================================================================
// Utilisé par le JavaScript de la fiche équipement

$routes->add('qr_api_generate', new Route(
    '/api/qr/generate/{id}',
    ['_controller' => 'Epiclub\\Controller\\QrRedirectController', 'action' => 'apiGenerateQr']
));


// =====================================================================
// GUIDE UTILISATEUR
// =====================================================================
// ROLE_USER — décision produit 2026-10-17 (#104) : réservé aux
// utilisateurs connectés (garde dans GuideController::index()).

$routes->add('guide_utilisateur', new Route(
    '/guide',
    ['_controller' => 'Epiclub\\Controller\\GuideController', 'action' => 'index']
));
    
    
return $routes;