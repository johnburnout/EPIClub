# EPIClub

<img src="https://epiclub.fr/wp-content/uploads/2026/07/EPIClub-logo.png" width="300">

## Objectif

EpiClub est une application de gestion et de journalisation des contrôles des Equipements de Protection Individuelle.

## Prérequis

* php >= 8.4
* mySQL >= 5.7
* ext-gd

## Installation

* Téléchargez la dernière version [ici](https://github.com/johnburnout/EPIClub/releases)
* Dézippez le dossier sur votre serveur.
* Parametrez le serveur pour qu'il pointe sur le dossier `public`.
* Ouvrez l'url dans votre navigateur et suivez les instructions.

> **SMTP est facultatif.** Vous pouvez passer cette étape lors de l'installation.
> Les fonctionnalités d'envoi de mail (notifications, mot de passe oublié) seront
> désactivées jusqu'à ce que vous configuriez SMTP dans l'administration.

> **À la fin de l'installation**, supprimez le dossier `setup/` de votre serveur.
> Tant qu'il est présent, l'application affichera une erreur 403 sur `/setup/`.

## Développement

### Réinitialiser une installation

Pour repartir de zéro en développement :

```bash
php bin/reset-install.php
```

Ce script supprime la base de données et le fichier `.env.local.php`.
Il refuse de s'exécuter depuis le navigateur (protection `PHP_SAPI !== 'cli'`).

### Lancer le serveur de dev

```bash
php -S localhost:8000 -t public public/router.php
```

## Licence

LGPL-3.0-or-later — voir [licence.md](licence.md).