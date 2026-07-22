<?php

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();

// UTILISATEUR
$routes->add('index', new Route('/', ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'index']));
$routes->add('dashboard', new Route('/tableau_de_bord', ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'dashboard']));

$routes->add('login', new Route('/se_connecter', ['_controller' => 'Epiclub\\Controller\\AppUserAuthController', 'action' => 'login']));
$routes->add('logout', new Route('/se_deconnecter', ['_controller' => 'Epiclub\\Controller\\AppUserAuthController', 'action' => 'logout']));

$routes->add('create_account', new Route('/creer_compte', ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'edit']));
$routes->add('my_account', new Route('/mon_compte', ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'account']));
$routes->add('forgot_password', new Route('/mot_de_passe_oublie', ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'forgotPassword']));
$routes->add('reset_password', new Route('/regenerer_mot_de_passe', ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'resetPassword']));

$routes->add('equipement_list', new Route('/equipements', ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'list']));
$routes->add('equipement_show', new Route('/equipements/equipement-{id}', ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'show']));
$routes->add('equipement_edit', new Route('/equipements/equipement_modification-{id}', ['_controller' => 'Epiclub\\Controller\\EquipementController', 'action' => 'edit'])); // ✅ AJOUTER

// CONTROLES
$routes->add('controle_list', new Route('/admin/controles', ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'list']));
$routes->add('controle_edit', new Route('/admin/controles/edit/{id}', ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'edit']));
$routes->add('controle_add_equipement', new Route('/admin/controles/add-equipement/{controle_id}', ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'addEquipement']));
$routes->add('controle_update_ligne', new Route('/admin/controles/update-ligne/{id}', ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'updateLigne']));
$routes->add('controle_cloturer', new Route('/admin/controles/cloturer/{id}', ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'cloturer']));
$routes->add('controle_creer', new Route('/admin/controles/creer', ['_controller' => 'Epiclub\\Controller\\ControleController', 'action' => 'create']));

// ADMINISTRATEUR
$routes->add('club_show', new Route('/admin/club', ['_controller' => 'Epiclub\\Controller\\ClubController', 'action' => 'show']));

// CATEGORIE - DELETE avant SHOW
$routes->add('categorie_list', new Route('/admin/categories', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'list']));
$routes->add('categorie_create', new Route('/admin/categories/nouvelle', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'edit']));
$routes->add('categorie_update', new Route('/admin/categories/categorie_modification-{id}', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'edit']));
$routes->add('categorie_delete', new Route('/admin/categories/supprimer', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'delete']));
$routes->add('categorie_show', new Route('/admin/categories/categorie-{id}', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'show']));

// ACQUISITION
$routes->add('acquisition_list', new Route('/admin/acquisitions', ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'list']));
$routes->add('acquisition_create', new Route('/admin/acquisitions/nouvelle', ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'create']));
$routes->add('acquisition_edit', new Route('/admin/acquisitions/acquisition_modification-{id}', ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'update']));
$routes->add('acquisition_show', new Route('/admin/acquisitions/acquisition-{id}', ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'show']));
$routes->add('acquisition_valider', new Route('/admin/acquisitions/valider/{id}', ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'valider']));
$routes->add('acquisition_ligne_edit', new Route('/admin/acquisitions/ligne_modification-{id}', ['_controller' => 'Epiclub\\Controller\\AcquisitionLineController', 'action' => 'modifyLine']));
$routes->add('acquisition_ligne_delete', new Route('/admin/acquisitions/ligne_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\AcquisitionLineController', 'action' => 'deleteLine']));

// FOURNISSEUR - DELETE avant SHOW
$routes->add('fournisseur_list', new Route('/admin/fournisseurs', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'list']));
$routes->add('fournisseur_create', new Route('/admin/fournisseurs/nouveau', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'edit']));
$routes->add('fournisseur_update', new Route('/admin/fournisseurs/fournisseur_modification-{id}', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'edit']));
$routes->add('fournisseur_delete', new Route('/admin/fournisseurs/supprimer', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'delete']));
$routes->add('fournisseur_show', new Route('/admin/fournisseurs/fournisseur-{id}', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'show']));

// UTILISATEUR - DELETE avant SHOW
$routes->add('utilisateur_list', new Route('/admin/utilisateurs', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'list']));
$routes->add('utilisateur_create', new Route('/admin/utilisateurs/nouveau', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'edit']));
# @deprecated $routes->add('utilisateur_update', new Route('/admin/utilisateurs/utilisateur_modification-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'edit']));
$routes->add('utilisateur_delete', new Route('/admin/utilisateurs/utilisateur_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'delete']));
$routes->add('utilisateur_show', new Route('/admin/utilisateurs/utilisateur-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'show']));

// EMPLACEMENT - DELETE avant SHOW
$routes->add('emplacement_list', new Route('/admin/emplacements', ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'list']));
$routes->add('emplacement_create', new Route('/admin/emplacements/nouveau', ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'edit']));
$routes->add('emplacement_update', new Route('/admin/emplacements/emplacement_modification-{id}', ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'edit']));
$routes->add('emplacement_delete', new Route('/admin/emplacements/supprimer', ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'delete']));
$routes->add('emplacement_show', new Route('/admin/emplacements/emplacement-{id}', ['_controller' => 'Epiclub\\Controller\\EmplacementController', 'action' => 'show']));