# EPIClub
![EPIClub](https://epiclub.fr/wp-content/uploads/2025/08/cropped-EPIClub-e1755818346875.png)
## Objectif
EPIClub est un logiciel de gestion en ligne des Equipements de Protection Individuelle à destinations des clubs, établissements scolaires 
, éducateurs sportifs, entreprises de travaux en hauteur et autres... 

C'est un logiciel multiplateforme et mutiutilisateurs avec journalisation automatique des contrôles effectués.
Il repose sur une une architecture client-serveur utilisant une base de données mySQL pilotée par une interface accessible par le navigateur web d'un smartphone ou d'un ordinateur.
L'identification des équipements peut se faire par le scan d'un qrcode affecté à chaque lot. 

La gestion et le contrôle des EPI peut se faire de manière fluide et régulière simplifiant cette tâche qui constitue une obligation légale pour les utilisateurs auxquels se destine EPIClub.
## Installation
### Prérequis
#### Site web operationnel
* Un serveur web
* php (>7)
* mySQL
#### Générateur de qrcodes
* Installer [composer](https://getcomposer.org/) sur votre serveur.
* A l'aide du terminal, positionnez vous à la racine de votre siteet installez endroid/qr-codes :
```
composer require endroid/qr-code
```
### Installation du logiciel
* Copier l'ensemble des fichiers et dossiers du projet dans un répertoire accessible par un navigateur web
* Importer la base epi.sql dans votre serveur de base de données (phpmyadmin ou ligne de commande).
### Parametrage du logiciel
* Modifier le fichier config_template.php avec les données de votre site
* Renommer le fichier config__template.php en config.php
* Accédez à votre site "https://votre.site/init_admin.php" et crééz votre compte administrateur
* Renommez ou détruisez le fichier init_admin.php