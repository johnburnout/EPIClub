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

// MONITEUR
// todo all controle links

// ADMINISTRATEUR
$routes->add('club_show', new Route('/admin/club', ['_controller' => 'Epiclub\\Controller\\ClubController', 'action' => 'show']));

$routes->add('categorie_list', new Route('/admin/categories', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'list']));
$routes->add('categorie_create', new Route('/admin/categories/nouvelle', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'edit']));
$routes->add('categorie_update', new Route('/admin/categories/epi_modification-{id}', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'edit']));
$routes->add('categorie_show', new Route('/admin/categories/categorie-{id}', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'show']));
$routes->add('categorie_delete', new Route('/admin/categories/categorie_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\CategorieController', 'action' => 'delete']));

$routes->add('acquisition_list', new Route('/admin/acquisitions', ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'list']));
$routes->add('acquisition_create', new Route('/admin/acquisitions/nouvelle', ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'create']));
$routes->add('acquisition_edit', new Route('/admin/acquisitions/acquisition_modification-{id}', ['_controller' => 'Epiclub\\Controller\\AcquisitionController', 'action' => 'update']));

$routes->add('fournisseur_list', new Route('/admin/fournisseurs', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'list']));
$routes->add('fournisseur_create', new Route('/admin/fournisseurs/nouveau', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'edit']));
$routes->add('fournisseur_update', new Route('/admin/fournisseurs/fournisseur_modification-{id}', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'edit']));
$routes->add('fournisseur_show', new Route('/admin/fournisseurs/fournisseur-{id}', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'show']));
$routes->add('fournisseur_delete', new Route('/admin/fournisseurs/fournisseur_supprimer-{id:\d+}', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'delete']));

$routes->add('utilisateur_list', new Route('/admin/utilisateurs', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'list']));
# @deprecated ? $routes->add('utilisateur_create', new Route('/admin/utilisateurs/nouveau', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'edit']));
# @deprecated $routes->add('utilisateur_update', new Route('/admin/utilisateurs/utilisateur_modification-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'edit']));
$routes->add('utilisateur_show', new Route('/admin/utilisateurs/utilisateur-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'show']));
# @deprecated $routes->add('utilisateur_delete', new Route('/utilisateurs/utilisateur_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'delete']));
