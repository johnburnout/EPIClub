# EPIClub

<img src="https://epiclub.fr/wp-content/uploads/2026/07/EPIClub-logo.png" width="300">

## Objectif

EpiClub est une application de gestion et de journalisation des contrôles des Equipements de Protection Individuelle.

## Prérequis

* php >= 8.4
* MySQL >= 8.0 ou MariaDB >= 10.6 (MySQL 5.7 fonctionne encore mais n'est plus maintenu depuis octobre 2023)
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

### Installation en ligne de commande

Même résultat que l'assistant, sans navigateur (hébergeur, Virtualmin,
console d'hébergement). À lancer **sous l'utilisateur propriétaire des
fichiers**, jamais en root :

```bash
EPICLUB_DB_PASS='…' EPICLUB_ADMIN_PASSWORD='…' php bin/installer.php --site-name="EPIClub – Club X" --root-url=https://epi.club-x.fr --db-name=clubx_epiclub --db-user=clubx --admin-username=jdupont --admin-email=j.dupont@club-x.fr --admin-nom=Dupont --admin-prenom=Jean --club-nom="Club X" --club-email=contact@club-x.fr --activite=Escalade
```

`php bin/installer.php --aide` liste toutes les options (SMTP, mot de
passe généré, `--json`…). Les mots de passe ne passent jamais en
argument : variables d'environnement ou fichiers. Codes de sortie : 0
succès, 2 paramètres invalides, 3 prérequis, 4 autre opération en cours,
8 installation interrompue, 9 déjà installé.

### Derrière un reverse proxy

Si un proxy (nginx, Apache, Traefik, Cloudflare Tunnel…) reçoit les
connexions HTTPS et les transmet à EPIClub, déclarez son adresse dans
`.env.local.php` :

```php
'TRUSTED_PROXIES' => '127.0.0.1',            // IP ou plages CIDR, séparées par des virgules
```

Sans cette clé, EPIClub voit toutes les requêtes venir du proxy en HTTP :
cookie de session sans `Secure`, pas de HSTS, et limitation des tentatives
de connexion commune à tous les utilisateurs. Seuls `X-Forwarded-For`,
`X-Forwarded-Proto` et `X-Forwarded-Port` sont lus, et seulement quand la
requête vient d'un proxy déclaré. Le proxy doit transmettre l'en-tête
`Host` d'origine (Apache : `ProxyPreserveHost On` ; nginx :
`proxy_set_header Host $host;`). Les mots-clés `PRIVATE_SUBNETS` (réseaux
privés) et `REMOTE_ADDR` (toute adresse qui se connecte, à réserver au cas
où PHP n'est joignable que par le proxy) sont acceptés.

Installation directe sur le serveur web (cas de Virtualmin) : ne rien
ajouter.

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

### Sauvegarde planifiée (ligne de commande)

La même archive, à programmer en cron sous l'utilisateur du domaine :

```bash
php bin/sauvegarder.php --quiet
```

L'archive `var/backups/epiclub-sauvegarde-…-planifiee.zip` apparaît dans
la page Sauvegarde / restauration. Seules les `BACKUP_RETENTION`
dernières sauvegardes planifiées sont conservées (`--garder=N` pour
changer). `--sql-seul` produit un simple dump SQL. Une sauvegarde ne
démarre pas pendant une mise à jour ou une restauration (code de
sortie 4).

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
release avec sa somme SHA-256. Ne cocher « pre-release » que pour une
version bêta (voir ci-dessous). Pour tester la construction en local :
`bin/build-release.sh v1.0.1`.

3. Une fois l'archive attachée par la CI, la **signer** sur la machine du
   mainteneur (jamais en CI : la clé privée ne doit pas être sur GitHub) :

   ```bash
   bin/signer-release.sh v1.0.1
   ```

   Le script contrôle l'archive, la signe avec [minisign](https://jedisct1.github.io/minisign/)
   (clé `~/.minisign/epiclub.key`, mot de passe demandé) et attache
   `epiclub-v1.0.1.zip.minisig` à la release. L'updater intégré refuse une
   archive sans signature valide : tant qu'elle n'est pas signée, la
   version reste « en préparation » sur les instances.

Pour vérifier à la main une archive téléchargée (première installation) :

```bash
minisign -V -m epiclub-v1.0.1.zip -P RWS7IFVJZpdnhR9xY+Q9awMczhU106RdwsMWLKVAv27NlQD1947YNsX4
```

Depuis la v1.0.0, une version **bêta** (ex. `v1.1.0-beta.1`) se publie
avec `--prerelease` : les instances en canal `stable` ne la voient pas,
celles en canal `beta` (`UPDATE_CHANNEL=beta`) la reçoivent. Une
version stable se publie sans `--prerelease`.

L'updater interroge l'API GitHub, limitée à 60 requêtes/heure par adresse
IP sans authentification (partagées avec les autres sites du serveur).
Après un échec, GitHub n'est pas réinterrogé avant 10 minutes. Sur une IP
partagée, ajouter un jeton dans `.env.local.php` porte la limite à 5 000
requêtes/heure : `'GITHUB_TOKEN' => 'github_pat_…'` (jeton « fine-grained »
sans aucune permission, « Public repositories » ; il n'est envoyé qu'à
`api.github.com`).

### Tests

```bash
vendor/bin/phpunit                # tests unitaires
vendor/bin/phpunit --testsuite Integration   # tests d'intégration + sécurité
```

Les tests d'intégration démarrent automatiquement un serveur de dev sur
`BASE_URL` (défaut : `http://localhost:8000`) si aucun serveur n'écoute,
et l'arrêtent en fin de suite. Un serveur déjà lancé manuellement est
réutilisé (et laissé intact).

### JavaScript et CSP

Les pages HTML sont servies avec une CSP stricte : `script-src 'self'
'nonce-…' https://cdn.jsdelivr.net`, sans `'unsafe-inline'`. Dans les
gabarits :

- un script inline porte le nonce de la requête :
  `<script nonce="{{ csp_nonce }}">` ;
- aucun attribut `onclick=`, `onsubmit=`, `onchange=`… (bloqués par le
  navigateur, sans message visible). Utiliser les attributs gérés par
  `public/assets/js/app.js` : `data-confirm="Message"` (bouton, lien ou
  formulaire), `data-auto-submit` (liste déroulante), `data-print`,
  `data-alert="Message"`, `data-fallback-src="…"` (image de secours), ou
  un `addEventListener` dans le script de la page.

`tests/Unit/TemplatesCspTest.php` vérifie ces deux règles sur tous les
gabarits.

## Licence

LGPL-3.0-or-later — voir [licence.md](licence.md).