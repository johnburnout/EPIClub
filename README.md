# EPIClub

![EPIClub](https://epiclub.fr/wp-content/uploads/2025/08/cropped-EPIClub-e1755818346875.png)

## Objectif

EPIClub est un logiciel de gestion en ligne multiplateforme et mutiutilisateurs des Equipements de Protection Individuelle avec journalisation automatique des contrôles effectués.
Le logiciel repose sur une base de données mySQL pilotée par une interface accessible par le navigateur web d'un smartphone ou d'un ordinateur.

## Prérequis

* php >= 7.4
* mySQL >= 5.4
* ext-gd

## Installation

* Copier l'ensemble des fichiers et dossiers du projet dans un répertoire accessible par un navigateur web
* Importer la base epi.sql dans votre serveur de base de données (phpmyadmin ou ligne de commande).

## Paramétrage du logiciel

* Modifier le fichier config_template.php avec les données de votre site
* Renommer le fichier config__template.php en config.php
* Accédez à votre site "https://votre.site/init_admin.php" et crééz votre compte administrateur
* Renommez ou détruisez le fichier init_admin.php
