<?php

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();

$routes->add('index', new Route('/', ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'index']));
$routes->add('dashboard', new Route('/tableau_de_bord', ['_controller' => 'Epiclub\\Controller\\IndexController', 'action' => 'dashboard']));

$routes->add('login', new Route('/se_connecter', ['_controller' => 'Epiclub\\Controller\\AppUserAuthController', 'action' => 'login']));
$routes->add('logout', new Route('/se_deconnecter', ['_controller' => 'Epiclub\\Controller\\AppUserAuthController', 'action' => 'logout']));

$routes->add('create_account', new Route('/creer_compte', ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'edit']));
$routes->add('my_account', new Route('/mon_compte', ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'account']));
$routes->add('forgot_password', new Route('/mot_de_passe_oublie', ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'forgotPassword']));
$routes->add('reset_password', new Route('/regenerer_mot_de_passe', ['_controller' => 'Epiclub\\Controller\\AppUserRegisterController', 'action' => 'resetPassword']));

$routes->add('epi_list', new Route('/epis', ['_controller' => 'Epiclub\\Controller\\EpiController', 'action' => 'list']));
$routes->add('epi_create', new Route('/epis/nouveau', ['_controller' => 'Epiclub\\Controller\\EpiController', 'action' => 'edit']));
$routes->add('epi_update', new Route('/epis/epi_modification-{id}', ['_controller' => 'Epiclub\\Controller\\EpiController', 'action' => 'edit']));
$routes->add('epi_show', new Route('/epis/epi-{id}', ['_controller' => 'Epiclub\\Controller\\EpiController', 'action' => 'show']));
$routes->add('epi_delete', new Route('/epis/epi_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\EpiController', 'action' => 'delete']));

$routes->add('epi_acquisition_list', new Route('/epi_acquisitions', ['_controller' => 'Epiclub\\Controller\\EpiAcquisitionController', 'action' => 'list']));
$routes->add('epi_acquisition_create', new Route('/epi_acquisitions/nouvelle', ['_controller' => 'Epiclub\\Controller\\EpiAcquisitionController', 'action' => 'create']));
$routes->add('epi_acquisition_update', new Route('/epi_acquisitions/acquisition_modification-{id}', ['_controller' => 'Epiclub\\Controller\\EpiAcquisitionController', 'action' => 'update']));
$routes->add('epi_acquisition_show', new Route('/epi_acquisitions/acquisition-{id}', ['_controller' => 'Epiclub\\Controller\\EpiAcquisitionController', 'action' => 'show']));
# $routes->add('epi_delete', new Route('/epis/epi_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\EpiController', 'action' => 'delete']));

$routes->add('epi_acquisition_ligne_create', new Route('/epi_acquisitions/acquisition-{id}/lignes_ajoute', ['_controller' => 'Epiclub\\Controller\\EpiAcquisitionController', 'action' => 'create_lines']));
$routes->add('epi_acquisition_ligne_update', new Route('/epi_acquisitions/acquisition-{id}/lignes_modifie', ['_controller' => 'Epiclub\\Controller\\EpiAcquisitionController', 'action' => 'update_lines']));
# $routes->add('epi_delete', new Route('/epis/epi_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\EpiController', 'action' => 'delete']));

$routes->add('epi_categorie_list', new Route('/epi_categories', ['_controller' => 'Epiclub\\Controller\\EpiCategorieController', 'action' => 'list']));
$routes->add('epi_categorie_create', new Route('/epi_categories/nouvelle', ['_controller' => 'Epiclub\\Controller\\EpiCategorieController', 'action' => 'edit']));
$routes->add('epi_categorie_update', new Route('/epi_categories/epi_modification-{id}', ['_controller' => 'Epiclub\\Controller\\EpiCategorieController', 'action' => 'edit']));
$routes->add('epi_categorie_show', new Route('/epi_categories/categorie-{id}', ['_controller' => 'Epiclub\\Controller\\EpiCategorieController', 'action' => 'show']));
$routes->add('epi_categorie_delete', new Route('/epi_categories/categorie_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\EpiCategorieController', 'action' => 'delete']));

$routes->add('fournisseur_list', new Route('/fournisseurs', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'list']));
$routes->add('fournisseur_create', new Route('/fournisseurs/nouveau', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'edit']));
$routes->add('fournisseur_update', new Route('/fournisseurs/fournisseur_modification-{id}', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'edit']));
$routes->add('fournisseur_show', new Route('/fournisseurs/fournisseur-{id}', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'show']));
$routes->add('fournisseur_delete', new Route('/fournisseurs/fournisseur_supprimer-{id:\d+}', ['_controller' => 'Epiclub\\Controller\\FournisseurController', 'action' => 'delete']));

# $routes->add('fabricant_list', new Route('/fabricants', ['_controller' => 'Epiclub\\Controller\\FabricantController', 'action' => 'list']));
# $routes->add('fabricant_create', new Route('/fabricants/nouveau', ['_controller' => 'Epiclub\\Controller\\FabricantController', 'action' => 'edit']));
# $routes->add('fabricant_update', new Route('/fabricants/fabricant_modification-{id}', ['_controller' => 'Epiclub\\Controller\\FabricantController', 'action' => 'edit']));
# $routes->add('fabricant_show', new Route('/fabricants/fabricant-{id}', ['_controller' => 'Epiclub\\Controller\\FabricantController', 'action' => 'show']));
# $routes->add('fabricant_delete', new Route('/fabricants/fabricant_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\FabricantController', 'action' => 'delete']));

$routes->add('utilisateur_list', new Route('/utilisateurs', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'list']));
$routes->add('utilisateur_create', new Route('/utilisateurs/nouveau', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'edit']));
$routes->add('utilisateur_update', new Route('/utilisateurs/utilisateur_modification-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'edit']));
$routes->add('utilisateur_show', new Route('/utilisateurs/utilisateur-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'show']));
# $routes->add('utilisateur_delete', new Route('/utilisateurs/utilisateur_supprimer-{id}', ['_controller' => 'Epiclub\\Controller\\UtilisateurController', 'action' => 'delete']));

