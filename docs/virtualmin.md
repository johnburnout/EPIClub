# EPIClub et Virtualmin : installation et sauvegardes

> **Statut : étude et proposition.** Rien n'est développé. Ce document
> décrit l'existant, ce que Virtualmin propose, les options possibles et
> ce qu'il faudrait écrire. La solution retenue est décrite au § 5.
>
> Version étudiée : v2.2.0, octobre 2026.
>
> **Décision du 25/10/2026 : les mises à jour sortent du champ.** Les
> super-administrateurs des clubs continuent de les faire depuis la page
> *Mise à jour*. Ils sont prévenus des nouvelles versions par une liste
> de diffusion. L'étude porte donc sur l'**installation** et les
> **sauvegardes**. Les pistes de mise à jour depuis Virtualmin (§ 4.2)
> restent notées pour mémoire.
>
> **Décision du 25/10/2026 : point d'entrée.** Une page
> d'administration sur `admin.epiclub.fr` (§ 5), hébergée **sur le même
> serveur Virtualmin que les instances**. L'agent est appelé par sudo.

## 1. Le besoin

Les instances EPIClub (une par club, a priori un serveur virtuel
Virtualmin chacune) sont hébergées sous Virtualmin. On veut pouvoir
lancer depuis Virtualmin :

- l'**installation** d'une nouvelle instance ;
- ses **mises à jour** (fichiers et migrations SQL), ce qui a été écarté
  depuis (voir la décision en tête du document) ;
- ses **sauvegardes** (base et fichiers).

Aujourd'hui ces trois opérations ne passent que par le navigateur :
l'assistant `setup/`, la page *Mise à jour* et la page *Sauvegarde /
restauration*. Elles sont donc réservées au super-administrateur du
club, et on ne peut ni les planifier ni les lancer sur plusieurs
instances à la fois.

## 2. L'existant

### 2.1 Installation (`setup/`)

- `app/bootstrap.php` sert `setup/install.php` sur **toutes** les URL
  tant que l'installation n'est pas terminée (`SETUP_COMPLETE` absent
  de `.env.local.php`, et aucun utilisateur en base).
- L'assistant compte 7 étapes (`setup/steps/step_*.php`), chacune avec
  un jeton CSRF de session :
  1. `1` : vérifie les prérequis (`setup/requierements.php` : PHP ≥ 8.4,
     extensions `gd`, `fileinfo`, `pdo_mysql` et `zip`, limites d'upload
     de 12 Mo), puis enregistre `SITE_NAME` et `ROOT_URL`. Crée
     `.env.local.php` en **0600** ;
  2. `dbms` : se connecte, crée la base si besoin (`utf8mb4`), **refuse
     une base qui contient déjà des tables**, applique toutes les
     migrations avec `MigrationManager`, puis écrit `DB_HOST`, `DB_NAME`,
     `DB_USER` et `DB_PASS` ;
  3. `admin` : crée le compte `ROLE_SUPER_ADMIN` **protégé**
     (`est_protege = 1`, mot de passe d'au moins 12 caractères). L'étape
     ne peut pas être rejouée. Elle génère `SECRET_KEY` et
     `CIPHER_METHOD` (AES-256-CBC), sans jamais régénérer une clé
     existante ;
  4. `smtp` (facultative) : écrit `MAILER_DSN`, `MAILER_FROM`,
     `MAILER_NAME` et `MAILER_ENABLED` ;
  5. `club` : nom, activité (Alpinisme, Escalade ou Spéléologie), email
     et téléphone du club ;
  6. `categories` : catégories prédéfinies selon l'activité
     (`setup/includes/categories_presets.php`) ;
  7. `final` : pose `SETUP_COMPLETE = true` et demande de supprimer
     `setup/`.
- La logique métier est **mêlée au HTML** dans chaque étape. Il n'existe
  aucun service d'installation réutilisable en ligne de commande.
- `bin/migrate.php` (CLI) applique les migrations en attente, après un
  dump SQL dans `var/backups/` (sauf avec `--sans-sauvegarde`). Codes de
  sortie : 0 ou 1.
- `bin/reset-install.php` (développement uniquement) supprime la base et
  `.env.local.php`. Il est interactif.

### 2.2 Mise à jour intégrée (`AppUpdateController`)

`perform()`, réservée au super-administrateur et protégée par un jeton
CSRF, enchaîne :

1. `GitHubReleaseProvider::getLatestRelease()` : dernière release de
   `johnburnout/EPIClub` sur le canal `UPDATE_CHANNEL` (`stable` ou
   `beta`), avec un cache d'une heure dans `var/tmp/update/` et
   `GITHUB_TOKEN` en option ;
2. téléchargement de `epiclub-vX.Y.Z.zip` et **vérification SHA-256**,
   obligatoire pour l'archive complète ;
3. contrôle anti « zip slip », puis extraction dans
   `var/tmp/update/extracted/` ;
4. refus d'une archive sans `vendor/` ;
5. **dump SQL** si la release apporte des migrations encore absentes de
   la base (annulation complète si le dump échoue) ;
6. préchargement des gabarits Twig de la page de résultat ;
7. `copyFiles()` : copie par-dessus l'installation, en excluant `var`,
   `vendor`, `.git`, `_storage`, `config`, `setup`, les fichiers `.env*`,
   `version.txt` et les données de `public/images` ;
8. migrations ;
9. écriture de `version.txt` ;
10. remplacement de `vendor/` d'un seul bloc (avec retour à l'ancien
    dossier en cas d'échec) ;
11. nettoyage.

Constats utiles pour la suite :

- **toute la logique est dans le contrôleur**. Elle n'est pas réutilisable
  en CLI sans extraction ;
- il n'y a **ni verrou ni mode maintenance**. Deux mises à jour
  simultanées, ou une requête servie pendant la copie, peuvent voir des
  versions mêlées ;
- les fichiers supprimés d'une version à l'autre ne sont jamais retirés
  (la copie se fait par-dessus) ;
- `databaseDsn()` ignore un port éventuel dans `DB_HOST`, alors que
  `bin/migrate.php` et `SauvegardeController` le gèrent. À corriger au
  passage.

### 2.3 Sauvegardes

- `DatabaseBackup` et `MySqlDatabaseDumper` produisent un dump SQL en
  PHP pur (sans `mysqldump` ni shell). Il est écrit de façon atomique
  (`.part` puis renommage), dans `var/backups/db_AAAA-MM-JJ_HHMMSS.sql`,
  avec une rétention `BACKUP_RETENTION` (5 par défaut). Il sert avant
  les migrations.
- `SauvegardeService` crée une **archive complète**
  `var/backups/epiclub-sauvegarde-AAAAMMJJ-HHMMSS.zip`. Elle contient un
  manifeste, la base et les fichiers des utilisateurs (`public/images`,
  `_storage/uploads`), avec des sommes SHA-256. Elle n'inclut **pas**
  `.env.local.php`, seulement une empreinte de `SECRET_KEY`. La
  restauration est vérifiée (version, migrations, clé) et précédée d'un
  « filet » (état courant sauvegardé), avec retour arrière automatique.
  Un **verrou fichier** (`var/backups/.verrou`) empêche deux opérations
  simultanées.
- Page `/admin/sauvegarde` (`SauvegardeController`) : on y exporte et
  restaure des archives. Une archive déposée par FTP dans `var/backups/`
  apparaît dans la liste.
- Il n'y a ni rétention des archives « manuelles » ni planification.

## 3. Ce que Virtualmin propose

### 3.1 Install Scripts (installeurs d'applications)

- Un installeur est **un fichier Perl** `epiclub.pl` qui définit des
  fonctions `script_epiclub_*` et se termine par `1;`.
- Les installeurs propres au serveur se déposent dans
  **`/etc/webmin/virtual-server/scripts/`**, ce qui demande les droits
  root. Les installeurs fournis se trouvent sous
  `<racine Webmin>/virtual-server/pro/scripts/`.
- Fonctions utiles pour EPIClub :

  | Fonction | Rôle pour EPIClub |
  |---|---|
  | `_desc`, `_longdesc`, `_category`, `_site` | Nom, description, catégorie |
  | `_uses` → `('php')` | Application PHP |
  | `_versions` | Versions proposées (liste figée dans le fichier, ou lue sur GitHub) |
  | `_release` | Révision de l'installeur lui-même |
  | `_php_vers`, `_php_fullver` | PHP 8, au moins 8.4 : Virtualmin bloque si la version du domaine ne convient pas |
  | `_php_modules` | `gd`, `fileinfo`, `pdo_mysql`, `zip` : Virtualmin tente de les installer |
  | `_php_vars` | `upload_max_filesize` et `post_max_size` à 12M (+) |
  | `_commands` | `unzip` |
  | `_dbs` → `('mysql')`, `_depends` | Le domaine doit avoir MySQL |
  | `_passmode` | Identifiant et mot de passe de l'admin, avec longueur minimale (12) |
  | `_params`, `_parse`, `_check` | Formulaire (dossier, base) et validation. `_check` sert aussi en CLI |
  | `_files` | Fichiers à télécharger (l'archive et son `.sha256`), mis dans `/tmp/.webmin` |
  | `_install(d, ver, opts, files, upgrade, user, pass)` | Installation, ou **mise à jour** quand `$upgrade` est défini |
  | `_uninstall` | Suppression (fichiers et tables) |
  | `_realversion` | Lit `version.txt` : détecte une mise à jour faite hors Virtualmin (par la page *Mise à jour*) |

- Côté **mise à jour**, Virtualmin rappelle `_install` avec `$upgrade`.
  Il faut alors conserver la configuration, ne pas recréer la base et ne
  pas appeler `_uninstall`. Virtualmin signale les installeurs dont une
  version plus récente est connue, et l'interface propose de les mettre
  à jour.
- En ligne de commande :

  ```bash
  virtualmin install-script --domain club.fr --type epiclub --version latest --path / --db mysql club_epiclub
  ```

  ```bash
  virtualmin list-scripts --domain club.fr
  ```

  ```bash
  virtualmin install-script --domain club.fr --type epiclub --version latest --upgrade <ID>
  ```

  Ces commandes se scriptent pour **toutes les instances**.
- **À vérifier sur le serveur réel** :
  - les installeurs personnalisés sous **Virtualmin GPL** (les forums
    disent que oui, mais la documentation officielle ne le confirme
    pas ; les installeurs *fournis* sont, eux, une fonction Pro) ;
  - le comportement exact de `--upgrade` sur la version installée ;
  - la façon propre de faire pointer le docroot sur `public/` depuis
    `_install` (voir § 6).

### 3.2 Sauvegardes Virtualmin

- **Sauvegardes planifiées** par domaine ou pour tous les domaines.
  Elles couvrent le répertoire personnel (donc tout EPIClub : code,
  `.env.local.php`, `var/backups`, `_storage`, `public/images`) et les
  bases MySQL (dump fait par Virtualmin). Destinations possibles :
  fichier local, SSH/FTP, S3 et autres. Rétention et sauvegardes
  incrémentales sont prévues. La restauration se fait par domaine.
- **Commandes avant / après la sauvegarde**, sur une sauvegarde
  planifiée :
  - elles s'exécutent **en root** ;
  - **une seule fois pour toute la sauvegarde**, pas par domaine ;
  - **uniquement pour les sauvegardes planifiées** (pas avec « Backup
    now ») ;
  - une régression a été signalée en 7.7 : il faudra tester sur la
    version installée.
- Des **répertoires exclus** peuvent être définis par domaine. On y
  mettrait `var/tmp`, `var/cache` et les `vendor.ancien-*`. Le nom exact
  du réglage reste à vérifier dans la version installée.

### 3.3 Tâches planifiées (cron)

- Virtualmin et Webmin permettent de créer des **tâches cron sous
  l'utilisateur du domaine** (module *Scheduled Cron Jobs*, ou accès
  cron accordé au propriétaire du domaine). La sortie est envoyée par
  mail (`MAILTO`).
- Le module Webmin **Custom Commands** (*Others → Custom Commands*)
  ajoute dans l'interface des **boutons** qui lancent une commande
  prédéfinie, éventuellement avec des paramètres (une liste de domaines,
  par exemple), sous l'utilisateur de son choix. C'est la manière la
  plus simple d'avoir une page « EPIClub » dans Virtualmin sans écrire
  de module.

## 4. Options comparées

### 4.1 Installation

| | I1 – Assistant web (actuel) | I2 – `bin/installer.php` | I3 – Install Script Virtualmin |
|---|---|---|---|
| Lancement | Zip déposé à la main, docroot réglé, navigateur | SSH ou bouton *Custom Commands*, sous l'utilisateur du domaine | Interface Virtualmin (*Install Scripts*) ou `virtualmin install-script` |
| Base de données | Créée par l'assistant (si les droits le permettent) | Base existante (créée par Virtualmin) ou option de création | **Créée par Virtualmin** (`--db mysql …`) |
| Version de PHP et extensions | Vérifiées, pas corrigées | Vérifiées (code 3) | **Vérifiées et installées** par Virtualmin |
| Super-admin protégé | Oui | Oui (mêmes règles) | Oui, via I2 (identifiant et mot de passe saisis dans Virtualmin) |
| Club, SMTP, catégories | Saisis dans l'assistant | Options, ou valeurs par défaut modifiables ensuite | Valeurs par défaut (nom du domaine, email du propriétaire), à ajuster ensuite dans l'application |
| Développement | Aucun | Moyen : extraire la logique de `setup/steps` dans un service | I2 **plus** environ 200 lignes de Perl, et un accès root pour le déposer |
| Dépendance à Virtualmin | Aucune | Aucune (utile partout) | Forte (format Perl, GPL ou Pro à vérifier) |

### 4.2 Mises à jour (écartées, pour mémoire)

> Hors champ depuis la décision du 25/10/2026 : on garde **U1**, avec
> une annonce des versions par liste de diffusion. U2 et U3 ne sont pas
> développées.

| | U1 – Page *Mise à jour* (actuelle) | U2 – `bin/mettre-a-jour.php` (cron ou *Custom Commands*) | U3 – Mise à jour de l'Install Script |
|---|---|---|---|
| Qui la lance | Le super-admin du club | L'hébergeur (bouton, cron, boucle sur les instances) | L'hébergeur dans Virtualmin |
| Automatique | Non | Oui : `--verifier` (mail si une version est disponible) ou `--appliquer` la nuit | Avertissement de Virtualmin, mise à jour en un clic |
| Plusieurs instances | Une par une | Une boucle shell | `virtualmin install-script --upgrade` en boucle |
| Sécurité (SHA-256, dump, verrou) | SHA-256 et dump, **pas de verrou** | Les mêmes, **avec** verrou et maintenance | Celles d'U2 (`_install` appelle U2) |
| Versions connues | GitHub (canal) | GitHub (canal) | `_versions` : liste figée (il faut republier `epiclub.pl` à chaque release) **ou** lue sur GitHub depuis Perl |
| Développement | Aucun | Extraire `MiseAJourService` du contrôleur | U2 plus le Perl |

Point important : quelle que soit l'option, U1 **doit rester**, car les
clubs non hébergés sous Virtualmin s'en servent. U1 et U2 partageraient
alors le même service et le même verrou.

### 4.3 Sauvegardes

| | B1 – Sauvegarde Virtualmin seule | B2 – B1 + `bin/sauvegarder.php` par cron du domaine | B3 – B1 + commande « avant sauvegarde » |
|---|---|---|---|
| Contenu | Répertoire personnel et dump MySQL de Virtualmin | B1, **plus** l'archive EPIClub (restaurable depuis l'application, ailleurs que sous Virtualmin) | Comme B2 |
| Restauration | Virtualmin, domaine entier, `SECRET_KEY` comprise | Domaine entier (Virtualmin) **ou** données seules (page `/admin/sauvegarde`) | Comme B2 |
| Planification | Sauvegarde planifiée de Virtualmin | cron de l'utilisateur, **avant** l'heure de la sauvegarde Virtualmin | Déclenchée par Virtualmin, donc toujours synchronisée |
| Pièges | Le dump de Virtualmin n'est pas lisible par la restauration d'EPIClub | Deux horaires à coordonner. Les archives occupent de la place : il faut une rétention | Exécutée en root et une seule fois : il faut un script qui boucle sur les instances avec `su`. Ne s'exécute pas pour « Backup now » |
| Développement | Aucun (documentation seulement) | Petit (le service existe, il faut la CLI et la rétention) | B2 plus un script shell |

Point d'attention : une sauvegarde Virtualmin **contient
`.env.local.php`**, donc les mots de passe de la base et du SMTP et la
`SECRET_KEY`. C'est nécessaire pour une restauration complète, mais la
destination doit être protégée (chiffrement ou accès restreint).

## 5. Recommandation : une console `admin.epiclub.fr`

> **Décision du 25/10/2026 : point d'entrée.** L'hébergeur pilote
> l'installation et les sauvegardes depuis une **page d'administration
> sur `admin.epiclub.fr`**, et non depuis l'interface de Virtualmin.
> Virtualmin reste le moteur : serveurs virtuels, bases, PHP et
> sauvegardes planifiées.

### 5.1 Architecture

```
admin.epiclub.fr (console PHP, son propre serveur virtuel)
   │  sudo epiclub-agent <action> <arguments validés>
   ▼
/usr/local/sbin/epiclub-agent (root, liste blanche d'actions)
   ├─ virtualmin create-domain / set-php-directory / modify-web / backup-domain …
   └─ su <utilisateur du domaine> -c "php8.4 ~/epiclub/bin/installer.php …"
                                   "php8.4 ~/epiclub/bin/sauvegarder.php …"
```

Il y a trois briques :

1. **Dans EPIClub** : `bin/installer.php` et `bin/sauvegarder.php`
   (§ 6). Ils sont indispensables quelle que soit la façon de les lancer.
2. **L'agent `epiclub-agent`** : un script appartenant à root, installé
   sur le serveur Virtualmin. C'est **le seul** composant qui a des
   droits élevés. Il n'accepte qu'une liste fermée d'actions :
   - `lister` ;
   - `creer-instance` ;
   - `etat` ;
   - `sauvegarder` ;
   - `lister-sauvegardes` ;
   - `suivre <tâche>`.

   Il valide chaque argument (nom de domaine, adresse email, activité)
   et journalise chaque appel dans `/var/log/epiclub-agent.log`. La
   console l'appelle par **une seule règle sudoers** :
   `admin_epiclub ALL=(root) NOPASSWD: /usr/local/sbin/epiclub-agent *`.
   La console est sur le même serveur (décision du 25/10/2026). Si elle
   devait un jour changer de machine, on passerait par SSH avec une clé
   limitée à cette commande (`command="…"` dans `authorized_keys`).
3. **La console** : une application **Symfony complète**, installée par
   Composer, dans un **dépôt séparé** (nom proposé :
   `EPIClub-console`), sous une **licence non GPL** (§ 5.5). Elle ne
   possède **aucun** identifiant Virtualmin.

**Pourquoi pas l'API distante de Virtualmin ?** `remote.cgi` (HTTPS,
port 10000) exécute les mêmes commandes, mais elle est **réservée à
l'administrateur principal**. La console devrait alors stocker un
identifiant qui donne tout pouvoir sur le serveur. Avec l'agent, une
console compromise ne peut que créer des instances EPIClub et lancer
des sauvegardes.

**Opérations longues.** La création d'une instance prend de l'ordre
d'une minute et une sauvegarde peut être plus longue. L'agent lance donc
la tâche en arrière-plan et rend aussitôt un identifiant. Il écrit l'état
dans `/var/lib/epiclub-agent/taches/<id>.json` (en cours, réussie ou
échouée, code de sortie, dernières lignes du journal). La console
l'interroge avec `suivre <id>`. Le verrou unique (§ 6.2) empêche deux
opérations simultanées sur une même instance.

### 5.2 Pages de la console

| Page | Contenu | Agent |
|---|---|---|
| **Instances** | Domaine, club, version installée (`version.txt`, en lecture seule), espace disque, dernière sauvegarde (EPIClub et Virtualmin) | `lister` |
| **Nouvelle instance** | Domaine, nom et activité du club, email du club, identité et email du super-admin, catégories par défaut ou non, SMTP facultatif | `creer-instance` |
| **Fiche d'une instance** | État, journal des opérations, sauvegardes présentes, bouton « Sauvegarder maintenant » | `etat`, `sauvegarder`, `lister-sauvegardes` |
| **Sauvegardes** | Sauvegardes planifiées de Virtualmin et leurs derniers résultats | `virtualmin list-scheduled-backups` et `list-backup-logs` (via l'agent) |
| **Journal** | Qui a fait quoi, quand, avec quel résultat | Base de la console |

La console ne fait **pas de restauration** dans un premier temps. La
restauration des données reste dans la page `/admin/sauvegarde` de
l'instance, et celle du domaine entier dans Virtualmin. Ce sont des
opérations rares et sensibles, à ajouter plus tard si le besoin se
confirme.

### 5.3 Déroulé de `creer-instance`

1. `virtualmin create-domain --domain club-x.epiclub.fr --pass … --unix
   --dir --web --ssl --mysql …`, ou avec un **modèle de serveur
   EPIClub** (*Server Template*) préparé une fois dans Virtualmin : PHP
   8.4, limites d'upload, certificat Let's Encrypt.
2. Version de PHP du domaine : `virtualmin set-php-directory --domain …
   --dir . --version 8.4`.
3. Archive de la dernière release : téléchargée **une fois** dans un
   cache de l'agent, avec vérification du SHA-256, puis décompressée
   dans `~/epiclub` **sous l'utilisateur du domaine**.
4. Docroot réglé sur `~/epiclub/public` (`virtualmin modify-web
   --document-dir`, à vérifier sur la version installée).
5. `bin/installer.php` sous l'utilisateur du domaine. Les identifiants
   de la base viennent de Virtualmin. Les secrets passent par
   l'environnement, jamais par la ligne de commande.
6. **Mot de passe du super-admin** : il n'est ni saisi ni affiché dans
   la console. L'installeur crée le compte avec un mot de passe
   aléatoire, puis la console déclenche l'email « mot de passe oublié »
   de l'instance. Si SMTP n'est pas configuré, le mot de passe est
   affiché **une seule fois**.
7. Cron du domaine pour `bin/sauvegarder.php`, et ajout du domaine à la
   sauvegarde planifiée de Virtualmin.

Avec ce déroulé, l'**Install Script Virtualmin (I3) devient inutile** :
l'agent fait la même chose avec les commandes `virtualmin`, sans Perl
ni question GPL/Pro. Les boutons *Custom Commands* (§ 6.7) ne sont plus
qu'un plan de secours.

### 5.4 Sécurité de la console

- Comptes nominatifs de l'équipe d'hébergement, **TOTP obligatoire**,
  sessions courtes, CSRF sur toutes les actions.
- En option, restriction par adresse IP (`Require ip` dans Apache).
- Aucun secret d'instance stocké dans la console : ils restent dans le
  `.env.local.php` de chaque instance.
- Journal d'audit dans la console, en plus de celui de l'agent.

### 5.5 Dépôt, pile et licence de la console

Décision du 25/10/2026 : **dépôt séparé**, **architecture Symfony et
Composer**, **pas de licence GPL**.

- **Pile** : `symfony/skeleton` avec `symfony/webapp-pack`, soit :
  - SecurityBundle (comptes de l'équipe) ;
  - Twig ;
  - Doctrine ORM (base propre à la console : comptes, journal d'audit,
    suivi des tâches) ;
  - Messenger (optionnel, pour relever l'état des tâches longues) ;
  - `scheb/2fa-bundle` (TOTP) ;
  - le composant Process pour appeler l'agent, avec un tableau
    d'arguments et jamais une chaîne de shell ;
  - tests avec PHPUnit, analyse avec PHPStan, comme dans EPIClub.
- **L'agent** vit dans ce même dépôt (dossier `agent/`), mais il est
  déployé à part : copié dans `/usr/local/sbin/`, **propriété de root,
  non modifiable** par l'utilisateur de la console. Sinon, la règle
  sudo reviendrait à donner root à la console. Il ne dépend ni de
  Symfony ni de `vendor/` : c'est un seul fichier PHP ou shell, lisible
  et vérifiable.
- **Licence et EPIClub** : EPIClub est en LGPL-3.0-or-later. La console
  **n'intègre ni ne copie aucun code d'EPIClub**. Elle ne fait que lancer
  ses commandes (`bin/installer.php`, `bin/sauvegarder.php`) comme
  programmes séparés, ce qui ne la soumet pas à la licence d'EPIClub.
  Symfony et les bundles cités sont sous licence MIT, compatible avec
  une licence propriétaire. À trancher : licence **propriétaire**
  (« tous droits réservés ») ou **permissive** (MIT, par exemple). Il
  faudra vérifier la licence de chaque dépendance ajoutée
  (`composer licenses`).

### 5.6 Ordre de réalisation

1. Dans EPIClub : extraire l'installation de `setup/` dans un service,
   écrire `bin/installer.php` et `bin/sauvegarder.php`, ajouter le
   verrou unique (y compris dans la page *Mise à jour*). C'est utile
   même sans console.
2. Écrire `epiclub-agent` et sa règle sudoers, et le tester en ligne de
   commande sur le serveur.
3. Écrire la console, d'abord les pages Instances, Nouvelle instance et
   Sauvegarder maintenant, puis le suivi des sauvegardes Virtualmin et
   le journal.

## 6. Ce qu'il faudrait développer

### 6.1 Contrat commun des commandes `bin/*.php`

- **CLI uniquement** : `PHP_SAPI !== 'cli'` donne le code 2 (même garde
  que `bin/reset-install.php`). Les commandes ne demandent **rien de
  façon interactive**.
- **Utilisateur** : chaque commande refuse de s'exécuter en root
  (`posix_geteuid() === 0`), sauf avec `--autoriser-root`. Sinon, les
  fichiers créés appartiendraient à root et la page *Mise à jour* ne
  pourrait plus les écrire.
- **Secrets** : jamais en argument, car ils seraient visibles dans
  `ps`. Ils passent par des variables d'environnement
  (`EPICLUB_DB_PASS`, `EPICLUB_ADMIN_PASSWORD`, `EPICLUB_SMTP_PASSWORD`),
  par `--…-fichier=chemin` ou par l'entrée standard.
- **Sorties** :
  - texte lisible sur la sortie standard, erreurs sur la sortie
    d'erreur ;
  - `--quiet` : rien n'est affiché si tout va bien et qu'il n'y avait
    rien à faire, pour que cron n'envoie de mail qu'en cas
    d'évènement ;
  - `--json` : un objet unique sur la sortie standard, lisible par un
    script (Perl de l'Install Script, boucle shell).
- **Journal** : une ligne horodatée par étape dans
  `var/log/epiclub-cli.log` (0640, sans aucun secret). La même ligne
  part dans `error_log` pour garder la trace habituelle.
- **Codes de sortie** (communs aux deux commandes) :

  | Code | Signification |
  |---|---|
  | 0 | Succès, ou rien à faire |
  | 1 | Erreur inattendue |
  | 2 | Usage : option manquante ou invalide, lancement hors CLI, lancement en root |
  | 3 | Prérequis absents : version de PHP, extension, droits d'écriture, espace disque |
  | 4 | Verrou occupé : une autre opération est en cours |
  | 5 | Archive d'installation rejetée (SHA-256, zip slip), pour l'Install Script |
  | 7 | Sauvegarde impossible |
  | 8 | Installation interrompue **après** modification (migration échouée) : il faut intervenir |
  | 9 | Déjà installé : `SETUP_COMPLETE`, ou base non vide |

### 6.2 Verrou

- **Verrou unique** `var/epiclub.lock` (`flock`, non bloquant), partagé
  par l'installation, la sauvegarde et la restauration (CLI et web)
  **et par la page *Mise à jour***. Une sauvegarde planifiée ne doit pas
  tourner pendant qu'un admin met à jour, ni l'inverse. Ce verrou
  remplace ou englobe `var/backups/.verrou`. Pour la mise à jour, c'est
  la seule retouche à prévoir : quelques lignes dans
  `AppUpdateController::perform()`.
- Le **mode maintenance** (503 pendant la copie des fichiers) n'est plus
  nécessaire au périmètre retenu. Il reste une amélioration possible de
  la page *Mise à jour*, indépendante de Virtualmin.

### 6.3 `bin/installer.php`

```bash
EPICLUB_DB_PASS=… EPICLUB_ADMIN_PASSWORD=… php8.4 bin/installer.php \
  --site-name="EPIClub – Club X" --root-url=https://epi.club-x.fr \
  --db-host=localhost --db-name=clubx_epiclub --db-user=clubx \
  --admin-username=jdupont --admin-email=j.dupont@club-x.fr \
  --admin-nom=Dupont --admin-prenom=Jean \
  --club-nom="Club X" --club-email=contact@club-x.fr --activite=Escalade \
  [--categories=defaut|aucune] [--smtp-dsn-fichier=…] [--creer-base] [--json]
```

Sans `EPICLUB_ADMIN_PASSWORD`, l'option `--admin-mot-de-passe-aleatoire`
crée le compte avec un mot de passe aléatoire. Il n'est restitué que
dans la sortie `--json`, pour le cas sans SMTP (§ 5.3, étape 6).

Étapes : vérifier les prérequis (code 3), contrôler que la base est
vide (code 9), écrire `.env.local.php` en 0600 (avec la clé générée une
seule fois), appliquer les migrations, créer le super-admin protégé,
puis le club et les catégories, poser `SETUP_COMPLETE` et neutraliser
`setup/` (le supprimer, ou le laisser puisqu'il est déjà refusé par
`setup/.htaccess` et la garde `SETUP_COMPLETE`). Le tout s'exécute sous
le verrou. Un échec en cours de route laisse un état identifiable :
`.env.local.php` sans `SETUP_COMPLETE`, que l'assistant web sait
reprendre.

### 6.4 `bin/mettre-a-jour.php` : abandonné

Les mises à jour restent sur la page *Mise à jour* (décision du
25/10/2026).

### 6.5 `bin/sauvegarder.php`

```bash
php8.4 bin/sauvegarder.php [--garder=7] [--sql-seul] [--quiet] [--json]
```

- Archive complète par `SauvegardeService::creer()`, ou dump SQL seul
  par `DatabaseBackup`.
- **Rétention** des archives manuelles et planifiées (`--garder`, ou
  `BACKUP_RETENTION`) : c'est la seule nouveauté de logique, le service
  ne faisant tourner aujourd'hui que les archives « avant
  restauration ».
- Un nom distinct (`-planifiee`) sépare ces archives des exports faits à
  la main dans la page.

### 6.6 Couche Virtualmin (option I3 : remplacée par l'agent, § 5.3)

- `ressources/virtualmin/epiclub.pl`, à copier par l'hébergeur dans
  `/etc/webmin/virtual-server/scripts/`.
- `_files` télécharge `epiclub-vX.Y.Z.zip` et `.sha256` depuis la
  release GitHub.
- `_install` décompresse dans `~/epiclub`, puis lance
  `bin/installer.php` **sous l'utilisateur du domaine** (avec le binaire
  PHP du domaine). Il règle le docroot sur `~/epiclub/public` et renvoie
  l'URL d'accès. Une mise à jour par Virtualmin (`$upgrade`) est
  refusée, avec un message qui renvoie à la page *Mise à jour*.
- `_realversion` lit `version.txt`.
- `_uninstall` supprime `~/epiclub` et les tables de la base.

### 6.7 Boutons *Custom Commands* (plan de secours)

Exemple : commande « Sauvegarder EPIClub », paramètre `domaine` (liste
des utilisateurs), exécutée en root :

```bash
su -s /bin/sh "$domaine" -c 'cd ~/epiclub && /usr/bin/php8.4 bin/sauvegarder.php'
```

Même principe pour « Installer EPIClub ». La sortie s'affiche dans
Webmin.

## 7. Risques et précautions

| Risque | Détail | Parade |
|---|---|---|
| **Utilisateur et droits sur les fichiers** | Sous Virtualmin, PHP-FPM ou FCGId tourne **sous l'utilisateur du domaine**, qui possède les fichiers : c'est ce qui permet à la page *Mise à jour* de fonctionner. Une commande lancée en root (cron root, *Custom Commands*, commande « avant sauvegarde ») crée des fichiers appartenant à root, qui ne sont plus modifiables par le web. | Toujours `su` ou `sudo -u` vers l'utilisateur du domaine. Les CLI refusent root (§ 6.1) |
| **`.env.local.php`** | Il contient les mots de passe de la base et du SMTP et `SECRET_KEY`. Il doit être en 0600 et appartenir à l'utilisateur du domaine. Il est inclus dans les sauvegardes Virtualmin. Une `SECRET_KEY` perdue rend les données chiffrées illisibles. | L'installeur CLI le crée en 0600 sous le bon utilisateur. Destination de sauvegarde protégée. Ne jamais le régénérer |
| **Version de PHP** | Virtualmin gère plusieurs PHP par domaine. Le `php` de la ligne de commande est souvent **la version système**, pas celle du domaine (≥ 8.4 exigée). | Appeler le binaire explicite (`/usr/bin/php8.4`), ou celui du domaine (`virtualmin list-php-directories`). Les CLI vérifient `PHP_VERSION` (code 3) |
| **Extensions** | `gd`, `fileinfo`, `pdo_mysql` et `zip`, à installer **pour la bonne version** (`php8.4-gd`…). La CLI et FPM peuvent avoir des `php.ini` différents. | Contrôle des prérequis en CLI. `_php_modules` dans l'Install Script |
| **Docroot** | EPIClub doit être servi depuis `public/`. Le docroot par défaut de Virtualmin est `~/public_html`. Le `.htaccess` racine refuse tout, par précaution. | Installer dans `~/epiclub` et régler le docroot (*Website Options*, ou `virtualmin modify-web --document-dir`, à vérifier), ou remplacer `public_html` par un lien vers `epiclub/public` |
| **Limites d'upload** | 12 Mo recommandés. Les réglages du domaine sont modifiables dans Virtualmin. | `_php_vars`, ou documentation |
| **Accès sortant** | La page *Mise à jour* interroge GitHub (`allow_url_fopen` ou cURL). La limite de l'API est de 60 requêtes par heure et par IP, partagée entre toutes les instances du serveur. | `GITHUB_TOKEN` et le cache existant. L'annonce par liste de diffusion évite que tous les admins vérifient en même temps |
| **Accès concurrents** | La sauvegarde planifiée peut tomber pendant une mise à jour lancée par un admin. Il n'y a pas de verrou aujourd'hui pour la mise à jour. | Verrou unique (§ 6.2) |
| **Espace disque** | Archives dans `var/backups`, sauvegardées **en plus** par Virtualmin, et anciens `vendor/`. | Rétention (`--garder`), exclusions de répertoires dans Virtualmin |
| **Commandes avant / après de Virtualmin** | Lancées en root, une fois par sauvegarde, pas pour « Backup now ». Régression signalée en 7.7. | Préférer B2 (cron du domaine). Si B3 : un script qui boucle, testé sur la version installée |
| **Console et agent** | L'agent tourne en root. Une faille d'injection dans ses arguments donnerait la main sur le serveur. La console est une cible exposée sur Internet. | Liste blanche d'actions, arguments validés par expression régulière, aucun appel à un shell avec une chaîne construite (`exec` avec un tableau d'arguments), TOTP, journal d'audit |
| **Install Scripts sous GPL** | Le support des installeurs personnalisés sans Pro n'est pas confirmé officiellement. | Tester un `epiclub.pl` minimal avant d'investir (raison de placer I3 en dernier) |

## 8. Sources

- Virtualmin, *Creating web app installers* :
  <https://www.virtualmin.com/docs/development/creating-web-app-installer/>
- Virtualmin, `install-script` (CLI) :
  <https://virtualmin.com/docs/development/api-programs/install-script>
- Commandes avant / après une sauvegarde planifiée (archive des
  forums) : <https://archive.virtualmin.com/node/3805>,
  <https://archive.virtualmin.com/node/25530>,
  <https://forum.virtualmin.com/t/command-to-run-after-backup/120541>
- Virtualmin, API distante (`remote.cgi`, réservée à l'administrateur
  principal) : <https://www.virtualmin.com/docs/development/remote-api/>
- Installeurs personnalisés sous GPL :
  <https://forum.virtualmin.com/t/virtualmin-gpl-scripts/113411>
