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
$routes->add('dashboard', new Route('/tableau_de_bord', ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'dashboard']));


// =====================================================================
// ROUTES SYSTEME (Configuration SMTP)
// =====================================================================
// ⚠️ Réservées à ROLE_SUPER_ADMIN (vérification dans IndexController)

$routes->add('system_settings', new Route(
    '/admin/system-settings',
    ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'systemSettings']
));

$routes->add('update_smtp', new Route(
    '/update-smtp',
    ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'updateSmtp'],
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
$routes->add('create_account',          new Route('/creer_compte',                       ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'edit']));
$routes->add('my_account',              new Route('/mon_compte',                          ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'account']));
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

// ROLE_ADMIN : modification et suppression
$routes->add('equipement_edit', new Route(
    '/equipements/equipement_modification-{id}',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'edit']
));
$routes->add('equipement_delete', new Route(
    '/equipements/equipement_supprimer-{id}',
    ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'delete'],
    [], [], '', [], ['POST']
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


// =====================================================================
// ADMINISTRATEUR — Réglages du club
// =====================================================================
// ⚠️ Réservé à ROLE_SUPER_ADMIN (vérification dans ClubController)

$routes->add('club_show', new Route(
    '/admin/club',
    ['_controller' => 'Epiclub\\Controller\\ClubController', 'action' => 'show']
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
// ROLE_USER (public ? à vérifier dans GuideController)

$routes->add('guide_utilisateur', new Route(
    '/guide',
    ['_controller' => 'Epiclub\\Controller\\GuideController', 'action' => 'index']
));
    
    
return $routes;