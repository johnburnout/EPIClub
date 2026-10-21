# EPIClub

<img src="https://epiclub.fr/wp-content/uploads/2026/07/EPIClub-logo.png" width="300">

## Objectif

EpiClub est une application de gestion et de journalisation des contrôles des Equipements de Protection Individuelle.

## Prérequis

* php >= 8.4
* mySQL >= 5.7
* ext-gd

## Installation

* Téléchargez l'archive `epiclub-<version>.zip` de la dernière version [ici](https://github.com/johnburnout/EPIClub/releases)
  (et non « Source code », qui ne contient pas les dépendances `vendor/`).
* Dézippez le dossier sur votre serveur.
* Parametrez le serveur pour qu'il pointe sur le dossier `public`.
* Ouvrez l'url dans votre navigateur et suivez les instructions.

> **SMTP est facultatif.** Vous pouvez passer cette étape lors de l'installation.
> Les fonctionnalités d'envoi de mail (notifications, mot de passe oublié) seront
> désactivées jusqu'à ce que vous configuriez SMTP dans l'administration.

> **À la fin de l'installation**, supprimez le dossier `setup/` de votre serveur.
> Tant qu'il est présent, l'application affichera une erreur 403 sur `/setup/`.

## Sauvegardes

### Sauvegardes automatiques avant migration

Avant chaque mise à jour qui contient des migrations de base de données
(page *Mise à jour* de l'administration, ou `php bin/migrate.php`), un
dump SQL complet est créé automatiquement dans `var/backups/`, sous le
nom `db_AAAA-MM-JJ_HHMMSS.sql`. Si ce dump échoue (espace disque, droits
d'écriture), la mise à jour est annulée avant toute modification.
Les 5 derniers dumps sont conservés.

Pour restaurer un dump :

```bash
mysql -u UTILISATEUR -p NOM_DE_LA_BASE < var/backups/db_2026-10-21_060000.sql
```

Pour ajuster la rétention, ajouter dans `.env.local.php` :

```php
'BACKUP_RETENTION' => 10,
```

Le dump est produit en PHP (aucune dépendance à `mysqldump`, rien
n'apparaît dans la liste des processus). En ligne de commande,
`php bin/migrate.php --sans-sauvegarde` permet de s'en passer.

### Sauvegarde complète (base + fichiers)

*Administration → Sauvegarde / restauration* (super-administrateur) :
archive `.zip` de la base **et** des photos, images et factures, avec
restauration vérifiée depuis l'application. Voir le guide utilisateur.

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

### Publier une release

1. Mettre à jour `version.txt` (ex. `1.0.1`) et `CHANGELOG.md`, commiter, pousser `main`.
2. Créer la release avec [gh](https://cli.github.com/) (notes tirées du CHANGELOG) :

   ```bash
   V=v1.0.1
   awk -v v="## [$V]" 'index($0,v)==1{f=1;next} f&&/^## \[/{exit} f' CHANGELOG.md > /tmp/notes.md
   gh release create "$V" --target main --title "$V" --notes-file /tmp/notes.md
   ```

La CI (`.github/workflows/release.yml`) construit alors l'archive complète
avec `bin/build-release.sh` (vendor/ inclus, sans tests) et l'attache à la
release avec sa somme SHA-256 ; l'updater intégré télécharge et vérifie ces
deux fichiers. Ne cocher « pre-release » que pour une version bêta (voir ci-dessous).
Pour tester la construction en local : `bin/build-release.sh v1.0.1`.

Depuis la v1.0.0, une version **bêta** (ex. `v1.1.0-beta.1`) se publie
avec `--prerelease` : les instances en canal `stable` ne la voient pas,
celles en canal `beta` (`UPDATE_CHANNEL=beta`) la reçoivent. Une
version stable se publie sans `--prerelease`.

### Tests

```bash
vendor/bin/phpunit                # tests unitaires
vendor/bin/phpunit --testsuite Integration   # tests d'intégration + sécurité
```

Les tests d'intégration démarrent automatiquement un serveur de dev sur
`BASE_URL` (défaut : `http://localhost:8000`) si aucun serveur n'écoute,
et l'arrêtent en fin de suite. Un serveur déjà lancé manuellement est
réutilisé (et laissé intact).

## Licence

LGPL-3.0-or-later — voir [licence.md](licence.md).