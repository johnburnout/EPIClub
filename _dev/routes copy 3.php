<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {

$routes->add('index', '/')->controller(['Epiclub\\Controller\\IndexController', 'index']);
$routes->add('dashboard', '/tableau_de_bord')->controller(['Epiclub\\Controller\\IndexController', 'dashboard']);

$routes->add('login', '/se_connecter')->controller(['Epiclub\\Controller\\AppUserAuthController', 'login']);
$routes->add('logout', '/se_deconnecter')->controller(['Epiclub\\Controller\\AppUserAuthController', 'logout']);

$routes->add('create_account', '/creer_compte')->controller(['Epiclub\\Controller\\AppUserRegisterController', 'edit']);
$routes->add('my_account', '/mon_compte')->controller(['Epiclub\\Controller\\AppUserRegisterController', 'account']);
$routes->add('forgot_password', '/mot_de_passe_oublie')->controller(['Epiclub\\Controller\\AppUserRegisterController', 'forgotPassword']);
$routes->add('reset_password', '/regenerer_mot_de_passe')->controller(['Epiclub\\Controller\\AppUserRegisterController', 'resetPassword']);

$routes->add('epi_list', '/epis')->controller(['Epiclub\\Controller\\EpiController', 'list']);
$routes->add('epi_create', '/epis/nouveau')->controller(['Epiclub\\Controller\\EpiController', 'edit']);
$routes->add('epi_update', '/epis/epi_modification-{id}')->controller(['Epiclub\\Controller\\EpiController', 'edit']);
$routes->add('epi_show', '/epis/epi-{id}')->controller(['Epiclub\\Controller\\EpiController', 'show']);
$routes->add('epi_delete', '/epis/epi_supprimer-{id}')->controller(['Epiclub\\Controller\\EpiController', 'delete']);

$routes->add('epi_acquisition_list', '/epi_acquisitions')->controller(['Epiclub\\Controller\\EpiAcquisitionController', 'list']);
$routes->add('epi_acquisition_create', '/epi_acquisitions/nouvelle')->controller(['Epiclub\\Controller\\EpiAcquisitionController', 'create']);
$routes->add('epi_acquisition_update', '/epi_acquisitions/acquisition_modification-{id}')
    ->controller(['Epiclub\\Controller\\EpiAcquisitionController', 'update'])
;
$routes->add('epi_acquisition_show', '/epi_acquisitions/acquisition-{id}')
    ->controller(['Epiclub\\Controller\\EpiAcquisitionController', 'show'])
;
# $routes->add('epi_acquisition_delete', '/epi_acquisitions/acquisition_supprimer-{id}')->controller(['Epiclub\\Controller\\EpiController', 'delete']);

$routes->add('epi_acquisition_ligne_create', '/epi_acquisitions/acquisition-{acquisition_id}/lignes_ajoute')
    ->controller(['Epiclub\\Controller\\EpiAcquisitionController', 'create_lines'])
;
$routes->add('epi_acquisition_ligne_update', '/epi_acquisitions/acquisition-{id}/lignes_modifie')
    ->controller(['Epiclub\\Controller\\EpiAcquisitionController', 'update_lines'])
;
# $routes->add('epi_delete', '/epis/epi_supprimer-{id}')->controller([])'Epiclub\\Controller\\EpiController', 'delete';

$routes->add('epi_categorie_list', '/epi_categories')
    ->controller(['Epiclub\\Controller\\EpiCategorieController', 'list'])
;
$routes->add('epi_categorie_create', '/epi_categories/nouvelle')->controller(['Epiclub\\Controller\\EpiCategorieController', 'edit']);
$routes->add('epi_categorie_update', '/epi_categories/epi_modification-{id}')->controller(['Epiclub\\Controller\\EpiCategorieController', 'edit']);
$routes->add('epi_categorie_show', '/epi_categories/categorie-{id}')->controller(['Epiclub\\Controller\\EpiCategorieController', 'show']);
$routes->add('epi_categorie_delete', '/epi_categories/categorie_supprimer-{id}')->controller(['Epiclub\\Controller\\EpiCategorieController', 'delete']);

$routes->add('fournisseur_list', '/fournisseurs')->controller(['Epiclub\\Controller\\FournisseurController', 'list']);
$routes->add('fournisseur_create', '/fournisseurs/nouveau')->controller(['Epiclub\\Controller\\FournisseurController', 'edit']);
$routes->add('fournisseur_update', '/fournisseurs/fournisseur_modification-{id}')->controller(['Epiclub\\Controller\\FournisseurController', 'edit']);
$routes->add('fournisseur_show', '/fournisseurs/fournisseur-{id}')->controller(['Epiclub\\Controller\\FournisseurController', 'show']);
$routes->add('fournisseur_delete', '/fournisseurs/fournisseur_supprimer-{id}')->controller(['Epiclub\\Controller\\FournisseurController', 'delete']);

# $routes->add('fabricant_list', '/fabricants')->controller([])'Epiclub\\Controller\\FabricantController', 'list';
# $routes->add('fabricant_create', '/fabricants/nouveau')->controller([])'Epiclub\\Controller\\FabricantController', 'edit';
# $routes->add('fabricant_update', '/fabricants/fabricant_modification-{id}')->controller([])'Epiclub\\Controller\\FabricantController', 'edit';
# $routes->add('fabricant_show', '/fabricants/fabricant-{id}')->controller([])'Epiclub\\Controller\\FabricantController', 'show';
# $routes->add('fabricant_delete', '/fabricants/fabricant_supprimer-{id}')->controller([])'Epiclub\\Controller\\FabricantController', 'delete';

$routes->add('utilisateur_list', '/utilisateurs')->controller(['Epiclub\\Controller\\UtilisateurController', 'list']);
$routes->add('utilisateur_create', '/utilisateurs/nouveau')->controller(['Epiclub\\Controller\\UtilisateurController', 'edit']);
$routes->add('utilisateur_update', '/utilisateurs/utilisateur_modification-{id}')->controller(['Epiclub\\Controller\\UtilisateurController', 'edit']);
$routes->add('utilisateur_show', '/utilisateurs/utilisateur-{id}')->controller(['Epiclub\\Controller\\UtilisateurController', 'show']);
# $routes->add('utilisateur_delete', '/utilisateurs/utilisateur_supprimer-{id}')->controller(['Epiclub\\Controller\\UtilisateurController', 'delete']);

};