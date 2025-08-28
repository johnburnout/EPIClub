<?php
	/**
	* Configuration site
	*
	*/
	
	$site_name = "Nom du site";
	$admin_email = "email@administrateur";
	$site_url = "https://adresse.du.site";
	$dossier = "/dossier_a°la_racine_du_site";
		
	/**
	* Configuration pour connection à la base de données mysql ou mariadb
	*
	*/
	
	$host = "localhost";
	$username = "login_base_de_donnees";
	$password = "mdp_base_de_donnees";
	$dbname = "nom_base_de_donnees";
	
	/**
	* Super admins
	*
	*/
	
	#$admins : utilisateurs aux droits d'administration irrévocables
	$admins = ['utilisateur_super_admin'];
	
?>