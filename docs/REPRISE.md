# EPIClub — Fichier de reprise

**Dernière mise à jour** : 2026-10-14 (fin de session #78 — Vague 14 en cours)
**Version courante** : v0.24.1
**Branche** : `main` (travail direct sur main, pas de PR)

---

## État du projet

Application de gestion EPI (équipements de protection individuelle)
pour club d'escalade. PHP 8.5, Symfony components (HttpFoundation,
Mailer, Mime), Twig, MySQL, PHPUnit 11.

### Versions récentes

| Version | Ticket | Description | Commit |
|---|---|---|---|
| v0.24.1 | #78 | Factoriser encryptionService() dans AbstractController (Dette 5) | `<hash>` |
| v0.24.0 | #77 | Suppression routes mortes `/mon_compte` et `/creer_compte` (Dette 14) | `1a6a162` |
| v0.23.0 | #76 | AppUserRegisterController — tests GET | `5580996` |
| v0.22.1 | #75 | Fix chemin template qr/choice_page.twig | `fec9d66` |
| v0.22.0 | #74 | IndexController — rattrapage Vague 11 | `537e2e8` |
| v0.21.0 | #73 | QrRedirectController — rattrapage Vague 11 | `6a82b55` |
| v0.20.0 | #72 | AcquisitionLigneManager — N+1 → 2 requêtes | `23d5fa0` |
| v0.19.0 | #71 | AcquisitionManager::findAll() — 2N+1 → 3 requêtes | `155b0a0` |
| v0.18.10 | #70 | JournalController — EncryptionService reçoit ConfigProvider | `acfca34` |
| v0.18.9 | #69 | JournalManager — suppression getPdo() + fix ORDER BY | `c6f57fb` |
| v0.18.8 | #68 | buildWhereClause() dans AbstractManager | `8747339` |
| v0.18.7 | #65 | FournisseurController factory fournisseurManager() | — |
| v0.18.6 | #64 | EmplacementController factory emplacementManager() | `1af7c98` |
| v0.18.5 | #63 | ClubController factory clubManager() | `79367c3` |
| v0.18.4 | #50c | MailerService reçoit ConfigProvider | `cd5b4d5` |
| v0.18.3 | #50b | EncryptionService centralisé | `12e8e47` |
| v0.18.2 | #59 | ROOT_URL dans system_settings | `cb49ca2` + merge `33e25c9` |
| v0.18.1 | #52 | Fix déchiffrement hash_remarques | `10e3744` |
| v0.18.0 | #50a-bis | getBaseUrl strict (ROOT_URL obligatoire) | — |

**Règle hash** : la ligne de la version courante porte `<hash>` (rempli
lors du ticket suivant). Les versions antérieures portent leur hash réel.

### Chantiers soldés

- **#78 — Factorisation `encryptionService()` dans `AbstractController`.**
  La factory était dupliquée à l'identique dans `ControleController`
  (#50b) et `JournalController` (#70) :
  `new EncryptionService($this->configProvider())`. Elle est désormais
  héritée par les 17 contrôleurs de l'application. Iso-comportement
  strict : 368 tests verts avant/après. Dette 5 soldée.
- **#77 — Suppression routes mortes `/mon_compte` et `/creer_compte`.**
  `AppUserRegisterController::account()` et `::edit()` rendaient des
  templates inexistants (`user_account.twig`, `user_register.twig`),
  d'où un 500 si tapées directement. Aucune référence dans l'UI,
  les templates ou les emails. Doublons de `UtilisateurController`
  (vrais templates `utilisateur_show.twig` / `utilisateur_form.twig`).
  Dette 14 soldée. 2 tests Unit supprimés (`testAccountDeniesAccessWhenUserNotGranted`,
  `testEditDeniesAccessWhenUserIsNotAdmin`).
- **#76 — `AppUserRegisterController` : tests Unit sur les méthodes GET.**
  5 tests Unit : gardes d'accès `account()` (`ROLE_USER`) et `edit()`
  (`ROLE_ADMIN`), rendus `forgotPassword()` GET, `forgotPasswordConfirm()`,
  `resetPassword()` GET token valide. Périmètre limité : les rendus
  de `account()`/`edit()` ne sont pas testés (templates manquants,
  Dette 14 — désormais soldée par #77 qui a supprimé ces méthodes).
- **#75 — Fix chemin template `qr/choice_page.twig`** : la route
  `/qr/choix/{id}` renvoyait 500 (`Twig\Error\LoaderError`). Le
  template existait dans `templates/qr/` mais le `render()` le
  cherchait à la racine. Fix 1 ligne + test Integration. Détecté en
  test manuel navigateur après #73.
- **#74 — `IndexController`** : rattrapage Vague 11. 2 factories
  `protected` (`environmentFileParser()`, `mailerService()`).
  5 tests Unit sur `index()`, `dashboard()`, `updateSmtp()`.
  `systemSettings()` et `testMail()` hors périmètre Unit (couvert en
  Integration / effet de bord réseau).
- 🎯 **Vague 11 — injection de dépendances** : **COMPLÈTE**.
  - Tous les contrôleurs ont une factory `protected` par manager.
  - **67 tests Unit** ajoutés sur les 3 derniers tickets
    (#63 Club, #64 Emplacement, #65 Fournisseur).
  - Pattern finalisé : `TestableXxxController` + builder, `postRequest()`
    avec `replace()` puis `set('csrf_token', ...)`, `validateCsrf()`
    no-op dans le testable.
- **#73 — `QrRedirectController`** : rattrapage Vague 11. 6 factories
  `protected` extraites (4 managers + `QrCodeGenerator` + `QrCodeReader`).
  4 tests Unit. Actions publiques non testables en Unit
  (`header()` + `exit`), Dette 13 documentée.
- **#72 — `AcquisitionLigneManager`** : suppression du N+1 sur
  `CategorieManager::findId()` dans `findByAcquisition()`.
  `CategorieManager::findIds()` batch, factory
  `protected acquisitionManager()` pour `delete()`.
  8 tests Unit, 1 test Integration de caractérisation.
  Iso-comportement strict (`isset()` conservé, `?? null` sur FK
  orpheline, ordre préservé).
- **#71 — `AcquisitionManager::findAll()`** : suppression du 2N+1.
  `findIds()` batch sur `FournisseurManager` et `UtilisateurManager`.
  10 tests Unit, 1 test Integration de caractérisation.
  Iso-comportement strict (`isset()` conservé, `?? null` sur FK
  orpheline, ordre préservé). Pour 100 acquisitions avec fournisseur
  + rédacteur : 201 requêtes → 3 requêtes fixes.
- **#70 — JournalController** : rattrapage Vague 11 + fix
  `ArgumentCountError`. Factories `journalManager()` et
  `encryptionService()`, propriétés `protected`, +2 tests Unit.
- **#65 — FournisseurController** : factory `fournisseurManager()`,
  20 tests Unit.
- **#64 — EmplacementController** : factory `emplacementManager()`,
  27 tests Unit.
- **#63 — ClubController** : factory `clubManager()`, 20 tests Unit.
- **#50 — ConfigProvider** : complet (#50a + #50b + #50c).
  - `ConfigProvider` est la source unique de vérité pour la config.
  - Plus de `$_ENV[...]` ni `getenv()` dans les services.
  - `EncryptionService` et `MailerService` reçoivent `ConfigProvider` en constructeur.
- **#59 — ROOT_URL** : éditable depuis `/admin/system-settings`.
- **#61, #62** : refactors ControleController (injection managers, test Integration edit view).

### État des tests
- **368 tests** verts (Unit + Domain), **861 assertions**.
- **9 tests Integration Controller** (nécessitent serveur HTTP sur
  `localhost:8000`), skippés si serveur absent :
  `AcquisitionControllerTest`, `AcquisitionLineControllerTest`,
  `ControleControllerEditViewTest`, `IndexControllerRootUrlTest`,
  `QrRedirectControllerChoicePageTest`, et les 4 autres.
- **2 tests Integration Domain** (`AcquisitionManagerFindAllIntegrationTest`,
  `AcquisitionLigneManagerFindByAcquisitionIntegrationTest`, nécessitent
  MySQL), skippés si base indisponible.
- Commande : `php vendor/bin/phpunit`
- Avec Integration : démarrer `php -S localhost:8000 -t public` dans un autre terminal.
- **Total avec serveur HTTP actif** : 368 Unit/Domain + 9 Integration Controller = **377 tests**.

---

## Prochaines cibles

### Vague 14 — en cours (refactors Engine + perf SQL + rattrapage Vague 11)

- ✅ **#68** — `buildWhereClause()` dans `AbstractManager`.
- ✅ **#69** — `JournalManager` : suppression `getPdo()`, extraction
  `buildOrderByClause()`, fix du bug `ORDER BY` (500).
- ✅ **#70** — `JournalController` : fix `ArgumentCountError`
  (`EncryptionService` sans `ConfigProvider`). Détecté en test manuel
  navigateur, jamais en Unit.
- ✅ **#71** — `AcquisitionManager::findAll()` : 2N+1 → 3 requêtes.
  `findIds()` batch sur FournisseurManager et UtilisateurManager.
  10 tests Unit + 1 test Integration de caractérisation.
- ✅ **#72** — `AcquisitionLigneManager::findByAcquisition()` :
  N+1 → 2 requêtes. `CategorieManager::findIds()` batch, factory
  `protected acquisitionManager()` pour `delete()`.
  8 tests Unit + 1 test Integration de caractérisation.
- ✅ **#73** — `QrRedirectController` : rattrapage Vague 11.
  6 factories `protected`, 4 tests Unit. Actions publiques non
  testables en Unit (`header()` + `exit`), Dette 13.
- ✅ **#74** — `IndexController` : rattrapage Vague 11 + tests Unit.
  2 factories `protected` (`environmentFileParser()`, `mailerService()`).
  5 tests Unit sur `index()`, `dashboard()`, `updateSmtp()`.
  `systemSettings()` et `testMail()` hors périmètre Unit (couvert en
  Integration / effet de bord réseau).
- ✅ **#75** — Fix chemin template `qr/choice_page.twig` : la route
  `/qr/choix/{id}` renvoyait 500 (`Twig\Error\LoaderError`). Le
  template existait dans `templates/qr/` mais le `render()` le
  cherchait à la racine. Fix 1 ligne + test Integration.
- ✅ **#76** — Tests Unit sur `AppUserRegisterController` (méthodes GET
  restantes : `login`, `register`, `forgotPassword` GET,
  `resetPassword` GET). Le cœur (handlers POST) est déjà couvert
  par 13 tests Unit.
- ✅ **#77** — Suppression routes mortes `/mon_compte` et `/creer_compte`
  (`AppUserRegisterController::account()` et `::edit()`), Dette 14 soldée.
- ✅ **#78** — Factorisation `encryptionService()` dans
  `AbstractController`. Suppression des duplications dans
  `ControleController` (#50b) et `JournalController` (#70). Dette 5 soldée.
- Candidats suivants (par ordre de pertinence) :
  - **Dette 10** — factoriser `acquisitionManager()` /
    `categorieManager()` dans les Managers (pattern Vague 11 appliqué
    aux Managers, pas seulement aux contrôleurs).
  - **Dette 6** — `EquipementManager::encryptionService()` divergent :
    utilise `new ConfigProvider(__DIR__ . '/../../.env.local.php')` en
    dur au lieu de `$this->configProvider()`. Alignement possible
    depuis #78 (la factory existe maintenant dans `AbstractController`,
    mais `EquipementManager` n'hérite pas d'`AbstractController`).
  - Fix `limit <= 0` dans `JournalManager` (`LIMIT -5` → 500).
  - Fix `'u.nom, u.prenom ASC'` (ambiguïté multi-colonnes).
  - `AbstractManager::findOneByCriteria()` générique ? (attention à
    `EquipementManager` — cas particulier `deleted_at IS NULL` — et à
    l'hydratation spécifique d'`AcquisitionManager`).
  - Nouveaux tests Unit sur les `Manager` non couverts.
  - `EncryptionService` : cache d'instance dans les factories.
  - Migration `@dataProvider` → `#[DataProvider]` sur les tests restants
    (cf. Dette 4).

---

## Dettes accumulées

Ces points ne sont **pas bloquants** mais à traiter dans des tickets
séparés quand le temps le permet.

### Dette 1 — Renommage `updateSmtp()` → `updateSystemSettings()`

- `IndexController::updateSmtp()` gère désormais SMTP **et** ROOT_URL.
- Le nom est imparfait, mais le renommer casse la route `/update-smtp`
  et les bookmarks.
- **Recommandation** : créer un alias `/update-system-settings` (double
  mapping), mettre à jour le Twig, garder `/update-smtp` en redirection.
- **Ticket à ouvrir** : oui, quand on aura du temps.

### Dette 2 — `EnvironmentFileParser::set()` réécrit à chaque appel

- Chaque `set()` déclenche un `_dump()` (écriture disque).
- `IndexController::updateSmtp()` fait 3-4 `set()` successifs → 3-4
  écritures.
- **Recommandation** : ajouter `setAll(array $values)` qui écrit une
  seule fois.
- **Ticket à ouvrir** : oui, si le sujet revient.

### Dette 3 — `admin` de la base test locale en `ROLE_SUPER_ADMIN`

- Setup de test, pas un changement projet.
- En prod, la séparation `ROLE_ADMIN` / `ROLE_SUPER_ADMIN` reste en place.
- **Pas de ticket** — c'est documenté ici.

### Dette 4 — Dépréciations PHPUnit `@dataProvider`

- PHPUnit 11 déprécie les annotations docblock `@dataProvider` au profit
  de l'attribut `#[DataProvider]`.
- Déjà corrigé sur `RootUrlValidatorTest` (#59).
- Reste à corriger sur les autres tests qui utilisent `@dataProvider`.
- Les tests #68 à #78 utilisent déjà l'attribut `#[DataProvider]` ou
  n'en ont pas besoin — pas d'ajout de dette.
- **Ticket à ouvrir** : oui, un petit ticket « chore: migrer
  @dataProvider vers #[DataProvider] ».

### Dette 6 — `EquipementManager::encryptionService()` instancié à chaque appel

- Factory `protected encryptionService()` crée une nouvelle instance
  à chaque `getHistoriqueControles()`.
- Acceptable vu l'usage peu fréquent.
- Si le manager se met à déchiffrer en masse, prévoir un cache.
- **Divergence** : utilise `new ConfigProvider(__DIR__ . '/../../.env.local.php')`
  en dur au lieu de `$this->configProvider()` (cf. Dette 5, maintenant
  soldée — mais `EquipementManager` n'hérite pas d'`AbstractController`).
- Pas de ticket pour l'instant.

### Dette 7 — `JournalManager` : bornage `limit` et ambiguïté multi-colonnes

- **Fix reportés** identifiés pendant #69 :
  - `limit <= 0` produit `LIMIT -5 OFFSET 0` → erreur MySQL 500.
    `AbstractManager::buildLimitClause()` retourne `''` pour
    `limit <= 0` (pas de limite) — divergence à trancher.
  - `order_by='controleur'` + `order_dir='asc'` produit
    `'u.nom, u.prenom ASC'` : le `ASC` ne s'applique qu'à `u.prenom`,
    `u.nom` reste en `ASC` implicite — ambiguïté multi-colonnes.
- **Ticket à ouvrir** : oui, un ticket « fix bornage limit + tri
  multi-colonnes JournalManager » — même pattern que #69 (fix
  documenté en `### Fixed`).

### Dette 8 — Exposition du hash de mot de passe dans `redacteur`

- `AcquisitionManager::findAll()` (et `findId()`, `findOneByCriteria()`)
  hydrate `redacteur` via `UtilisateurManager::findId()`, qui fait un
  `SELECT *` incluant `password`, `reset_token`, `reset_token_expires`,
  `reset_email_sent_at`.
- Conséquence : `$acquisition['redacteur']['password']` est accessible
  dans toutes les vues Twig qui affichent une acquisition.
- **Pas de correction dans #71** (iso-comportement strict) — le
  refactor `findIds()` conserve ce `SELECT *` à dessein.
- **Recommandation** : introduire un `UtilisateurManager::findPublicFields()`
  (ou une projection SQL) qui exclut les colonnes sensibles, puis
  migrer les appelants. À traiter dans un ticket dédié.
- **Ticket à ouvrir** : oui.

### Dette 9 — Absence de contraintes FOREIGN KEY sur `acquisition` et `acquisition_ligne`

- `SHOW CREATE TABLE acquisition` et `SHOW CREATE TABLE acquisition_ligne`
  ne déclarent aucune `CONSTRAINT ... FOREIGN KEY`. Or :
  - `acquisition.fournisseur_id` → `fournisseur.id`
  - `acquisition.saisie_par` → `utilisateur.id`
  - `acquisition_ligne.acquisition_id` → `acquisition.id`
  - `acquisition_ligne.categorie_id` → `categorie.id`
  sont tous `int NOT NULL` et référencent logiquement une autre table.
- Conséquence : rien n'empêche un `fournisseur_id = 999999` ou un
  `categorie_id = 999999` orphelin. D'où les `?? null` défensifs dans
  `AcquisitionManager::findAll()` (#71) et
  `AcquisitionLigneManager::findByAcquisition()` (#72) —
  **réellement atteignables**, pas du code mort.
- **Impact** : intégrité référentielle non garantie au niveau SGBD ;
  les FK orphelines doivent être gérées applicativement (ce que font
  déjà les `?? null`).
- **Recommandation** : ajouter les contraintes FK + `ON DELETE
  RESTRICT` (ou `SET NULL` avec colonne nullable, à trancher), mais
  cela nécessite de nettoyer les orphelins existants avant.
- **Ticket à ouvrir** : oui, mais **après** la Vague 14 (chantier
  schéma, à planifier avec migration de données).

### Dette 10 — Instanciation en dur des managers dans les Managers

- **`AcquisitionManager::findAll()`** fait `new FournisseurManager()` et
  `new UtilisateurManager()` : instances réelles, non injectables. Le
  mock PDO ne capte que la première requête (SELECT acquisition).
- **`AcquisitionLigneManager::findByAcquisition()`** fait
  `new CategorieManager()` — même problème.
- Conséquence : la revendication « 3 requêtes au lieu de 2N+1 » (#71) et
  « 2 requêtes au lieu de N+1 » (#72) n'est pas vérifiable en Unit.
  Elle ne l'est qu'en Integration (observation de la base).
- **Recommandation** : extraire des factories `protected
  fournisseurManager()`, `utilisateurManager()`, `categorieManager()`
  sur `AcquisitionManager` et `AcquisitionLigneManager`, surchargeables
  en test. Aligner sur `TestableJournalController` (Vague 14). Envisager
  une factorisation dans `AbstractManager`.
- **Ticket à ouvrir** : oui, dans la continuité de la Vague 14.
- Connexe : Dette 5 (soldée en #78).

### Dette 11 — `AcquisitionLigneManager::findId()` reste à 2 requêtes

- `findId()` fait `SELECT acquisition_ligne` + `CategorieManager::findId()`.
  Ce n'est pas un N+1 (2 requêtes fixes), mais ce n'est pas optimal.
- **Non traité dans #72** (périmètre strict : `findByAcquisition()` et
  `delete()` uniquement).
- **Recommandation** : aligner sur `findIds([$id])` (1 requête SQL au lieu
  de 2) dans un ticket « micro-perf Managers ». Vérifier au passage
  l'usage réel de `findId()` — il est appelé par `delete()` (2 fois
  potentiellement) et par les contrôleurs.
- **Ticket à ouvrir** : oui, mais **basse priorité** (pas un N+1).

### Dette 12 — `QrRedirectController` : méthodes privées mixtes

- Le contrôleur a des méthodes privées qui ne sont pas toutes pures
  (`getControleById`, `getUtilisateurById`) : elles délèguent à des
  managers via `method_exists()`, ce qui est un pattern fragile.
- **Recommandation** : aligner sur les méthodes de manager standard
  (`findId()` est présent partout) et supprimer les `method_exists()`.
  Vérifier ensuite que les 2 méthodes ne sont pas mortes (aucun appel
  apparent).
- **Ticket à ouvrir** : oui, **basse priorité** — non bloquant.

### Dette 13 — `QrRedirectController` utilise `header()` + `exit`

- Les 7 méthodes publiques (`redirect`, `choicePage`, `generateQr`,
  `downloadQr`, `viewQr`, `saveQr`, `apiGenerateQr`) appellent
  `header('Location: ...')` + `exit` (ou `echo` + `exit` pour les QR).
- Conséquence : **non testables en Unit** (le `exit` tue le processus
  PHP). Seul le constructeur et quelques méthodes privées pures sont
  testables (couverts par #73).
- **Recommandation** : refactor architectural — retourner des objets
  `Response` (Symfony HttpFoundation) au lieu d'appeler `header()` +
  `exit`. Nécessite d'adapter le front controller (`public/index.php`)
  pour faire `$response->send()`.
- **Ticket à ouvrir** : oui, mais **basse priorité** — le contrôleur
  fonctionne, il n'a juste pas de filet Unit sur les actions.
- Connexe : `QrCodeReader::read()` (avec `$outputDirectly = true`)
  utilise aussi `header()` + `exit`. Même problème.

---

## Règles de travail établies

- **Iso-comportement strict (avec exception fix de bug documenté)** :
  les refactors ne changent pas le comportement observable, **sauf**
  lorsqu'ils corrigent un bug documenté (500, injection SQL,
  corruption de données, comportement manifestement non intentionnel).
  Dans ce cas :
  - le fix est **isolé** dans le même commit, clairement identifiable ;
  - il est **testé** (test rouge d'abord sur le bug, puis vert après
    fix) ;
  - il est documenté dans la section `### Fixed` du CHANGELOG (Keep a
    Changelog) ;
  - le message de commit mentionne le fix (`refactor + fix: ...`).
- **Test rouge d'abord** : écrire le test qui échoue, puis implémenter.
  Pour les refactors iso-comportement : **test de caractérisation**
  (vert avant, vert après) — cf. #71 et #72.
- **Commit unique par ticket** : un commit = un ticket = une version.
- **Tag annoté** : `git tag -a vX.Y.Z -m "..."`.
- **Release GitHub** + **clôture issue** après chaque tag.
- **Mot-clé de clôture dans le commit** : ajouter `Closes #XX`
  (ou `Fixes #XX` / `Resolves #XX`) dans le corps du message de
  commit pour que GitHub ferme automatiquement l'issue au push
  sur `main`. Évite l'étape manuelle.
- **Travail direct sur `main`** : pas de branche feature pour les petits
  tickets. Branche feature (`feat/XX-...`) pour les tickets plus gros
  (comme #59).
- **CHANGELOG + version.txt** mis à jour à chaque release.
- **`version.txt` sans newline final** (iso-comportement).
- **Hash dans `docs/REPRISE.md`** : la ligne de la **version courante**
  porte `<hash>`. Le hash réel est rempli **au ticket suivant**, au
  moment où l'on connaît la version figée. Évite un commit cosmétique
  par ticket.

---

## Commandes utiles

Tests (depuis la racine du projet) :

- `php vendor/bin/phpunit` — suite complète.
- `php vendor/bin/phpunit tests/Unit/Engine/FooTest.php` — un fichier.
- `php vendor/bin/phpunit --filter testMethodName` — une méthode.
- `php vendor/bin/phpunit --display-deprecations` — affiche le détail
  des dépréciations PHPUnit.

Serveur HTTP pour tests Integration Controller :

- `php -S localhost:8000 -t public` (dans un second terminal).

Base de données de test :

- `mysql -u root epiclub -e "SELECT id, username, role FROM utilisateur WHERE username='admin';"`
- `mysql -u root epiclub -e "SHOW CREATE TABLE acquisition"`
- `mysql -u root epiclub -e "SHOW CREATE TABLE acquisition_ligne"`
  (⚠️ `\G` n'est pas interprété en mode `-e` sous MySQL 8+ — utiliser
  le client interactif pour le format vertical.)

Pattern release Git :

- `git add <fichiers>`
- `git commit -m "type(#ticket): description" -m "Closes #XX"`
- `git push origin main`
- `git tag -a vX.Y.Z -m "vX.Y.Z — #ticket description"`
- `git push origin vX.Y.Z`

Pattern non-régression (refactor iso-comportement) :

- Sauvegarder le fichier refactoré : `cp app/Domain/Xxx.php /tmp/Xxx.refactored.php`
- Restaurer la version HEAD~1 : `git checkout HEAD~1 -- app/Domain/Xxx.php`
- Lancer le test Integration : doit être **VERT** (caractérisation)
- Restaurer le refactor : `cp /tmp/Xxx.refactored.php app/Domain/Xxx.php`
- Relancer : doit être **VERT** (non-régression)

⚠️ **Ne pas utiliser `git stash`** pour cette procédure : il stashe
**tous** les fichiers modifiés, y compris les fixtures et tests
nouveaux (non suivis exclus). Utiliser `cp` + `git checkout HEAD~1 --
<fichier>` + `cp` retour. C'est ce qui a marché pour #71 et #72.

---

## Points de vigilance

- **`.env.local.php`** : non tracké par git (gitignore). Contient les
  secrets (`SECRET_KEY`, `DB_PASS`, `MAILER_DSN`, `ROOT_URL`).
- **`ROOT_URL` obligatoire** depuis v0.18.0 (#50a-bis) : `getBaseUrl()`
  throw si absent. Vérifier sa présence avant tout déploiement.
- **`SECRET_KEY` obligatoire** depuis #52 : `EncryptionService` throw
  si absente ou non-hex.
- **`MAILER_DSN` obligatoire** pour l'envoi d'emails : `MailerService`
  throw `MailDeliveryException` si absent (mais lecture lazy — pas au
  constructeur).
- **PHP 8.5** : `curl_close()` déprécié (no-op depuis 8.0).
  `hex2bin()` émet un warning si input non-hex — valider avant.
- **`ReflectionMethod::setAccessible()` déprécié en PHP 8.5** : no-op
  depuis PHP 8.1 (les méthodes `protected`/`private` sont accessibles
  par réflexion sans appel). Ne pas appeler `setAccessible(true)` dans
  les tests qui utilisent la réflexion sur des méthodes `protected`.
- **OpCache** : si `validate_timestamps=0`, redémarrer PHP-FPM après
  toute modif de `.env.local.php` (les tests Integration en dépendent).
- **Tests POST + CSRF** : `private const CSRF_TOKEN` doit être
  **déclarée** en haut de la classe de test. Sans elle,
  `self::CSRF_TOKEN` vaut `null` silencieusement, puis
  `ParameterBag::replace()` filtre la clé, d'où `submitted=NULL`
  côté `CsrfValidator`. Pattern retenu dans `postRequest()` :
  appeler d'abord `$request->request->replace($params)`, puis
  `$request->request->set('csrf_token', self::CSRF_TOKEN)`.
- **Testable controller** : surcharger `updateLastActivity()` en no-op
  pour éviter `new UtilisateurManager()` qui ouvre PDO. Pour les
  contrôleurs qui appellent `validateCsrf()`, surcharger aussi
  `validateCsrf()` en no-op (CSRF testé isolément dans
  `CsrfValidatorTest`). Deux patterns coexistent :
  - **Sans** `parent::__construct()` (Vague 11, `TestableClubController`
    etc.) : appelle directement les factories, ne teste pas le
    constructeur réel.
  - **Avec** `parent::__construct()` + surcharge `updateLastActivity()`
    (Vague 14, `TestableJournalController`, `TestableQrRedirectController`) :
    teste le constructeur réel. À privilégier pour les contrôleurs qui
    n'ont pas encore été migrés Vague 11 — c'est ce qui a permis de
    capturer le bug #70.
  - ⚠️ **Le constructeur parent appelle `Session::get('csrf_token')`**
    (AbstractController.php, pour le global Twig). Tout mock `Session`
    doit tolérer cet appel : ne pas utiliser `->with('user')` seul,
    mais `willReturnCallback` qui discrimine par clé. Sinon
    `Expectation failed ... 'csrf_token'`. Détecté sur #73.
  - **`ReflectionMethod::invoke()` sur la classe parente** : exécute
    le code de la classe où la méthode est **déclarée**, pas la
    surcharge du testable. Utile pour tester l'implémentation parente
    (comme `JournalController::encryptionService()` qui appelle
    `$this->configProvider()` surchargé). Inutile pour tester une
    surcharge de testable — il faut alors cibler la classe testable.
    Détecté sur #73.
  - ⚠️ **Surcharger une méthode parente dont un paramètre vient d'un
    autre namespace** : penser à ajouter le `use` correspondant, sinon
    PHP résout la classe dans le namespace courant et lève une erreur
    fatale (`Could not check compatibility between ... because class
    ... is not available`). Détecté sur #74
    (`TestableIndexController::validateCsrf()` et le
    `use Symfony\Component\HttpFoundation\Request`).
  - ⚠️ **`Session::isGranted()` dépend de `user['role']`** : pour
    tester une garde `deniAccessUnlessGranted('ROLE_ADMIN')` avec un
    utilisateur qui n'a que `ROLE_USER`, il faut passer un
    `user['role'] = 'ROLE_USER'` dans la Session (pas un mock
    `isGranted` → false). Voir `Session::ROLE_LEVELS` pour la
    hiérarchie. Détecté sur #76.
  - ⚠️ **Factory héritée dans `AbstractController` (#78)** : les
    contrôleurs qui déclaraient une factory locale identique (ex :
    `encryptionService()`) doivent la retirer, sinon la méthode locale
    masque celle du parent. Le test unitaire dédié à la factory
    parente (`ReflectionMethod(JournalController::class,
    'encryptionService')`) continue de fonctionner — `ReflectionMethod`
    résout les méthodes héritées. Détecté sur #78.
- **Copier un contrôleur** : bien vérifier le `namespace` et le nom de
  classe. Un copier-coller raté peut mettre `class FournisseurController`
  dans `FournisseurManager.php` (incident #64).
- **Testable abstract manager** : `AbstractManager::__construct()`
  ouvre une connexion PDO. La sous-classe de test
  (`TestableAbstractManager` dans
  `AbstractManagerBuildWhereClauseTest.php`) surcharge le
  constructeur en **no-op** pour les tests Unit des méthodes
  `protected` (`buildWhereClause`, `buildOrderClause`,
  `buildLimitClause`). Pattern à réutiliser pour les futurs tests
  sur `AbstractManager`. **`AbstractManager::$db` est `protected`**,
  donc une sous-classe de test peut faire `$this->db = $pdo;`.
- **Mocker `\PDO`** : PHPUnit 11 mocke bien `\PDO` et `\PDOStatement`
  (`createMock(\PDO::class)` fonctionne). Attention à quatre pièges :
  - `method('fetch')->willReturn(['total' => 0])` **ne matche pas**
    `fetch(\PDO::FETCH_ASSOC)` (arguments stricts). Utiliser
    `willReturnCallback(fn() => [...])` pour matcher tout appel.
  - `willReturnCallback` sur `prepare()` doit discriminer le count SQL
    du data SQL. Ne pas se fier à `str_contains($sql, 'COUNT(*)')` :
    la requête principale peut contenir une sous-requête `COUNT(*)`.
    Discriminer sur `str_contains($sql, 'ORDER BY')` (uniquement
    présent dans le data SQL).
  - **`PDOStatement::execute()` sans argument** : PHPUnit appelle le
    callback `willReturnCallback` avec `null`, pas `[]`. Typer
    `?array $params = null` puis `$params ?? []`. Sinon `TypeError`
    en `strict_types`. Détecté sur #71.
  - **Instanciation en dur dans les Managers** : `new
    FournisseurManager()` dans `AcquisitionManager::findAll()` et
    `new CategorieManager()` dans
    `AcquisitionLigneManager::findByAcquisition()` ne sont pas
    mockables. Le mock PDO ne capte que la première requête.
    Conséquence : impossible de compter les requêtes en Unit (cf.
    Dette 10). Pattern à surveiller pour les prochains tickets.
- **`header()` + `exit` dans un contrôleur** : non testable en Unit
  (le `exit` tue le processus PHP). Détecté sur
  `QrRedirectController` (#73). Si un autre contrôleur utilise ce
  pattern, mêmes limites. Cf. Dette 13.
- **Tests Unit de contrôleur — pas d'assertion sur le HTML** :
  ne PAS tester le contenu HTML (fragile, dépendant du template).
  Tester :
  - le code de statut (`200`, `302`) ;
  - les headers (`Location`) ;
  - les appels aux managers (mocks) ;
  - les flashs de session ;
  - les propriétés du DTO (`HandlerResult`).
  Le contenu HTML est testé en Integration HTTP. Détecté sur #74
  (`testDashboardRendersTableauDeBord` cherchait `'tableau_de_bord'`
  dans le HTML rendu).

---

## Contexte de la session précédente

- **#78** (v0.24.1) : factorisation `encryptionService()` dans
  `AbstractController`. Suppression des duplications dans
  `ControleController` (#50b) et `JournalController` (#70).
  Iso-comportement strict. Dette 5 soldée. 368 tests au total.
- **#77** (v0.24.0) : suppression routes mortes `/mon_compte` et
  `/creer_compte` (`AppUserRegisterController::account()` et `::edit()`)
  + 2 tests Unit. Aucune référence dans l'UI → routes dormantes cassées.
  Doublons de `UtilisateurController`. Dette 14 soldée.
- **#76** (v0.23.0) : `AppUserRegisterController` — 5 tests Unit
  sur les méthodes GET. Périmètre limité : les rendus de `account()`
  et `edit()` ne sont pas testés (templates manquants, Dette 14).
  370 tests au total.
- **#75** (v0.22.1) : fix chemin template `choice_page.twig` →
  `qr/choice_page.twig`. Bug détecté en test manuel après #73.
  `DatabaseFixture::createEquipement()` ajouté. 1 test Integration
  sur `/qr/choix/{id}`. 366 tests au total.
- **#74** (v0.22.0) : `IndexController` — rattrapage Vague 11.
  2 factories `protected` (`environmentFileParser()`,
  `mailerService()`). 5 tests Unit sur `index()`, `dashboard()`,
  `updateSmtp()`. Périmètre limité : `systemSettings()` couvert en
  Integration, `testMail()` hors périmètre (effet réseau SMTP).
  365 tests au total.
- **#73** (v0.21.0) : `QrRedirectController` — rattrapage Vague 11.
  6 dépendances (`EquipementManager`, `ControleManager`,
  `ControleLigneManager`, `UtilisateurManager`, `QrCodeGenerator`,
  `QrCodeReader`) passées en factories `protected` mockables.
  Constructeur réduit à `parent::__construct($session)`.
  4 tests Unit (constructeur, factory, getCurrentUser).
  **Périmètre limité** : actions publiques non testables à cause de
  `header()` + `exit` (Dette 13). 360 tests au total.
- **#72** (v0.20.0) : `AcquisitionLigneManager::findByAcquisition()` —
  suppression du N+1 sur `CategorieManager::findId()` via
  `findIds()` batch. Factory `protected acquisitionManager()` dans
  `delete()` (remplace `new AcquisitionManager()`). Périmètre strict :
  `findId()` et `findByReference()` restent inchangés.
  8 tests Unit, 1 test Integration de caractérisation.
- **#71** (v0.19.0) : `AcquisitionManager::findAll()` — suppression du
  2N+1. `findIds()` batch sur `FournisseurManager` et
  `UtilisateurManager`. 10 tests Unit, 1 test Integration de
  caractérisation. Premier ticket Vague 14 « perf SQL ».
- **#70** (v0.18.10) : `JournalController` — fix `ArgumentCountError`.
  Détecté en test manuel navigateur, jamais en Unit. Migré au pattern
  Vague 11 : factories `journalManager()` et `encryptionService()`.
  +2 tests Unit avec `TestableJournalController`.
- **#69** (v0.18.9) : `JournalManager` — suppression de `getPdo()`
  (code mort), extraction de `buildOrderByClause()` et **fix du bug**
  `ORDER BY c.date_debut DESC ASC` (500). +23 tests Unit. Premier
  ticket appliquant l'exception « fix de bug documenté » à la règle
  d'iso-comportement.
- **#68** (v0.18.8) : `AbstractManager::buildWhereClause()` extrait
  dans `AbstractManager`, 6 Managers migrés. +14 tests Unit. **Premier
  ticket Vague 14**.

**🎯 Vague 11 complète** (3 contrôleurs, 67 tests Unit).
**🎯 Vague 14 en cours** (#68 à #78 livrés).
**Prochaine session** : **Dette 10** — factories dans les Managers
(`AcquisitionManager`, `AcquisitionLigneManager`) pour rendre mockables
les managers imbriqués et permettre le comptage de requêtes en Unit.