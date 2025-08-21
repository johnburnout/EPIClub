# EPIClub
## Objectif
Créer un logiciel de gestion en ligne multiplateforme et mutiutilisateurs des Equipements de Protection Individuelle avec journalisation automatique des contrôles effectués.
Le logiciel repose sur une base de données mySQL pilotée par une interface accessible par le navigateur web d'un smartphone ou d'un ordinateur.
## Installation
### Prérequis
* Un serveur web
* php (>7)
* mySQL
### Générateur de qrcodes
* Installer [composer](https://getcomposer.org/) sur votre serveur.
* A l'aide du terminal, positionnez vous à la racine de votre siteet installez endroid/qr-codes :
```composer require endroid/qr-code
### Installation du logiciel
* Copier l'ensemble des fichiers et dossiers du projet dans un répertoire accessible par un navigateur web
* Importer la base epi.sql dans votre serveur de base de données (phpmyadmin ou ligne de commande).
### Parametrage du logiciel
* Modifier le fichier config_template.php avec les données de votre site
* Renommer le fichier config__template.php en config.php
* Accédez à votre site page : "https://votre.site/init_admin.php et crééz votre compte administrateur
* Renommez ou détruisez le dossier init_admin.php
## Licence
Logiciel sous licence libre (GPL) incluant la diffusion, la modification et l'utilisation à des fins non commerciales du logiciel et de ses dérivés.
L'auteur se réserve le droit de l'exploitration commerciale du logiciel et des services en lignes associés.
