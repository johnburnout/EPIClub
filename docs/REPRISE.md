# EPIClub — Fichier de reprise

**Dernière mise à jour** : 2026-10-15 (fin de session #86 — Vague 14 en cours)
**Version courante** : v0.26.1
**Branche** : `main` (travail direct, ticket #86)

---

## État du projet

Application de gestion EPI (équipements de protection individuelle)
pour club d'escalade. PHP 8.5, Symfony components (HttpFoundation,
Mailer, Mime), Twig, MySQL, PHPUnit 11.

### Versions récentes

| Version | Ticket | Description | Commit |
|---|---|---|---|
| v0.26.1 | #86 | Fin des @dataProvider — Dette 4 (chore PHPUnit) | `<hash>` |
| v0.26.0 | #85 | FOREIGN KEY acquisition/acquisition_ligne (Dette 9) + gardes suppressions | `5df96b6` |
| v0.25.4 | #84 | UtilisateurManager projection publique — Dette 8 (hash mdp dans redacteur) | `e630ef3` |
| v0.25.3 | #83 | JournalManager bornage limit + tri multi-colonnes (Dette 7) | `5643fcd` |
| v0.25.2 | #82 | AbstractManager::configProvider() + alignement EquipementManager (Dette 6) | `38bb7dc` |
| v0.25.1 | #81 | Fix IndexControllerRootUrlTest + cleanup tests/Domain/ | `8fd9087` |
| v0.25.0 | #80 | Factories Managers (Dette 10) — comptage de requêtes testable | `0c8456d` |
| v0.24.2 | #79 | AcquisitionLigneManager::findId() — 2 → 1 requête (Dette 11) | `60548ee` |
| v0.24.1 | #78 | Factoriser encryptionService() dans AbstractController (Dette 5) | `3b7b005` |
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
lors du ticket suivant, ou immédiatement si le hash est connu au moment
de la mise à jour de REPRISE). Évite un commit cosmétique par ticket.

### Chantiers soldés

- **#86 — Fin des `@dataProvider` (Dette 4 soldée).** Dernier usage
  docblock retiré (`ControleControllerHandlersTest` — l'attribut
  `#[DataProvider]` y était déjà en doublon). `--display-deprecations`
  ne rapporte plus aucune dépréciation de métadonnées docblock.
  Aucune autre annotation dépréciée détectée (`@depends`, `@covers`…).
- **#85 — FOREIGN KEY sur `acquisition` / `acquisition_ligne`
  (Dette 9 soldée).** Aucune FK n'existait sur ces tables (orphelins
  réellement atteignables, d'où les `?? null` défensifs #71/#72/#79).
  Migration `005_foreign_keys.sql` : `fk_acquisition_fournisseur`
  (RESTRICT), `fk_acquisition_saisie_par` (RESTRICT),
  `fk_acquisition_ligne_acquisition` (**CASCADE** — les lignes n'ont
  pas d'existence indépendante, le contrôleur supprimait déjà les
  lignes avant l'acquisition), `fk_acquisition_ligne_categorie`
  (RESTRICT). Conventions de nommage alignées sur `controle`/
  `controle_ligne` (`fk_<table>_<colonne>`). Purge auto des lignes
  orphelines ; contrôles préalables documentés en tête de migration
  (l'ALTER échoue franchement si des acquisitions violent la
  contrainte — vérifié : 0 orphelin sur la base de dev).
  **Fix de bug documenté** (garde applicative) : suppression d'une
  catégorie référencée par des lignes → orphelin silencieux ;
  suppression d'un utilisateur ayant saisi des acquisitions → idem.
  Ajout `CategorieManager::hasLignesAcquisition()` +
  `UtilisateurManager::hasAcquisitionsSaisies()` + gardes flash dans
  les contrôleurs (`FournisseurManager::hasAcquisitions()` existait).
  Tests rouges d'abord : 4 erreurs Unit (méthodes absentes). Test
  Integration `ForeignKeysIntegrationTest` (4 FK via
  information_schema, skip si migration 005 non appliquée — InnoDB
  rapporte RESTRICT implicite comme NO ACTION, les deux acceptés).
  Le test de caractérisation #79 « catégorie orpheline » devient un
  test du refus d'insertion par la contrainte (cas devenu
  inatteignable ; `?? null` défensif toujours couvert en Unit).
  4 tests Unit + 1 Integration ajoutés, 1 Integration réécrit.
  ⚠️ Base locale migrée (004 + 005 appliquées) ; une installation qui
  saute la migration 005 n'a pas les FK — le test Integration skip
  proprement dans ce cas.
- **#84 — `UtilisateurManager` : projection publique (Dette 8
  soldée).** `AcquisitionManager::findAll()` / `findId()` /
  `findOneByCriteria()` hydrataient `redacteur` via `findIds()` /
  `findId()` (SELECT *) : les vues Twig recevaient `password`,
  `reset_token`, `reset_token_expires`, `reset_email_sent_at`.
  Nouvelle constante `UtilisateurManager::PUBLIC_FIELDS` (id, nom,
  prenom, username, email, role, date_creation) + `findPublicId()` /
  `findPublicIds()` (colonnes en dur dans le SQL, pas d'injection).
  Les 3 points d'hydratation migrés. `findId()` / `findIds()` restent
  pour la logique interne (authentification — ils servent le mot de
  passe). Tests rouges d'abord : 5 erreurs Unit (méthodes absentes)
  + 3 erreurs (mock `findPublicIds` sans réponse). Preuve par capture
  du SQL (Unit) + test Integration sur base réelle (colonnes sensibles
  absentes de `redacteur`). 5 tests Unit + 1 test Integration ajoutés.
- **#83 — `JournalManager` : bornage `limit` + tri multi-colonnes
  (Dette 7 soldée).** Fix comportemental avec tests rouges d'abord
  (9 failures + 1 error prouvant les bugs) :
  - `getControlesClotures()` : `limit < 1` → `DEFAULT_LIMIT` (10),
    `page < 1` → `DEFAULT_PAGE` (1). Avant : `LIMIT -5 OFFSET 0`
    (erreur MySQL 500), `DivisionByZeroError` sur `limit=0` (prouvé
    en test), offset négatif sur `page<1`. Paramètres injectables
    via `$_GET` → réellement atteignables.
    ⚠️ Divergence assumée avec `AbstractManager::buildLimitClause()`
    (`limit <= 0` = pas de limite) : la pagination exige une limite
    positive pour `totalPages`.
  - `buildOrderByClause()` : direction appliquée à **chaque** colonne
    d'un tri multi-colonnes. Avant : `'u.nom, u.prenom ASC'` (ASC
    implicite sur `u.nom` — ambiguïté de tri contrôleur).
  - 6 tests Unit ajoutés (Bloc D, valeurs bindées capturées via
    `bindValue` — piège identifié : LIMIT/OFFSET sont bindés, pas
    interpolés, ils n'apparaissent jamais littéralement dans le SQL).
    Bloc B mis à jour (3 cas 'controleur').
  - Premier ticket avec branche feature + merge `--no-ff` sur main.
- **#82 — `AbstractManager::configProvider()` + alignement
  `EquipementManager` (Dette 6).** Ajout de la factory
  `configProvider()` dans `AbstractManager`, alignée sur
  `AbstractController` (#50a / #78). `EquipementManager::encryptionService()`
  utilise désormais `$this->configProvider()` au lieu de
  `new ConfigProvider(...)` en dur. Import `ConfigProvider` retiré
  (orphelin). 2 tests Unit ajoutés. Dette 6 soldée.
- **#81 — Fix 7 failures `IndexControllerRootUrlTest` + cleanup
  `tests/Domain/`.** Les 7 tests échouaient car `admin` en base de
  test n'a que `ROLE_ADMIN`, alors que `/admin/system-settings` et
  `/admin/update` exigent `ROLE_SUPER_ADMIN`. Ajout d'une fixture
  `promoteAdminToSuperAdmin()` dans
  `AbstractControllerTestCase::setUp()`. **Dette 3 soldée**
  (promotion automatique en test). Par ailleurs, `tests/Domain/`
  (1 fichier) est déplacé vers `tests/Unit/Domain/` pour uniformiser
  la nomenclature. Suite Integration : 102 tests verts (vs 95/102).
- **#80 — Factories de Managers dans les Managers (Dette 10).**
  Extraction de `AcquisitionManager::fournisseurManager()`,
  `AcquisitionManager::utilisateurManager()` et
  `AcquisitionLigneManager::categorieManager()`, mockables en test
  Unit. Surcharges dans `TestableAcquisitionManager` et
  `TestableAcquisitionLigneManager`. **Le comptage de requêtes est
  désormais testable** : `AcquisitionManagerFindAllTest` renforcé
  (comptage + valeurs), `AcquisitionLigneManagerFindIdTest` créé.
  Les optimisations #71, #72 et #79 sont maintenant vérifiables par
  test Unit, plus seulement par lecture du code.
- **#79 — `AcquisitionLigneManager::findId()` : 2 → 1 requête.**
  Remplacement de `new CategorieManager()->findId()` par
  `(new CategorieManager())->findIds([(int) $ligne['categorie_id']])`.
  Alignement sur `findByAcquisition()` (#72). Iso-comportement strict
  (`?? null` conservé pour FK orpheline). Dette 11 soldée.
  ⚠️ Le comptage de requêtes n'est pas testable en Unit/Integration
  (cf. Dette 10 : `new CategorieManager()` ouvre sa propre connexion
  PDO). Gain prouvé par lecture du code.
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
- **388 tests** Unit/Domain verts, **953 assertions**.
- **104 tests** Integration verts, **245 assertions** :
  - 4 Integration Domain (MySQL requis, dont `ForeignKeysIntegrationTest`).
  - 10 Integration Controller (serveur HTTP auto-démarré) — **plus de failures**.
- Commande Unit : `php vendor/bin/phpunit`
  (`defaultTestSuite="Unit"` dans `phpunit.xml`).
- Commande Integration : `php vendor/bin/phpunit --testsuite Integration`.
- Le serveur HTTP est désormais **démarré automatiquement** par les
  tests (classe `Epiclub\Tests\Support\WebServer`, cf. commit
  `d1e0bfa`) : inutile de lancer `php -S` dans un autre terminal.
  Un serveur déjà en écoute est réutilisé, jamais arrêté par les tests.
- **Total avec MySQL actif** : 388 + 104 = **492 tests**.

✅ ~~7 tests Integration Controller en échec préexistant :
`IndexControllerRootUrlTest`~~ → **résolu en #81** (promotion
`admin` → `ROLE_SUPER_ADMIN` dans `AbstractControllerTestCase`).

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
- ✅ **#79** — `AcquisitionLigneManager::findId()` : 2 → 1 requête.
  Dette 11 soldée. Comptage de requêtes non testable (Dette 10
  réaffirmée comme prérequis).
- ✅ **#80** — Factories de Managers dans `AcquisitionManager` et
  `AcquisitionLigneManager`. Dette 10 soldée. Comptage de requêtes
  désormais testable en Unit.
- ✅ **#81** — Fix 7 failures `IndexControllerRootUrlTest` + cleanup
  `tests/Domain/` → `tests/Unit/Domain/`. Dette 3 soldée.
- ✅ **#82** — `AbstractManager::configProvider()` + alignement
  `EquipementManager::encryptionService()`. Dette 6 soldée.
- ✅ **#83** — `JournalManager` : bornage `limit` (`LIMIT -5` → 500,
  `limit=0` → DivisionByZeroError) + tri multi-colonnes
  (`'u.nom, u.prenom ASC'` ambigu). Dette 7 soldée. Première branche
  feature + merge `--no-ff`.
- ✅ **#85** — FOREIGN KEY `acquisition` / `acquisition_ligne`
  (Dette 9 soldée) + gardes `hasLignesAcquisition()` /
  `hasAcquisitionsSaisies()`. Migration 005. Chantier schéma prévu
  « après la Vague 14 » : anticipé d'une version, base de dev propre
  (0 orphelin).
- ✅ **#86** — Fin des `@dataProvider` restants (Dette 4 soldée).
  1 doublon docblock/attribut retiré, zéro dépréciation PHPUnit.
- Candidats suivants (par ordre de pertinence) :
  - Fix `AbstractManager::findOneByCriteria()` générique ? (attention à
    `EquipementManager` — cas particulier `deleted_at IS NULL` — et à
    l'hydratation spécifique d'`AcquisitionManager`).
  - Nouveaux tests Unit sur les `Manager` non couverts.
  - Migration `@dataProvider` → `#[DataProvider]` sur les tests restants
    (cf. Dette 4).
  - **Dette 12** — `QrRedirectController` méthodes privées mixtes
    (basse priorité).
  - **Dette 13** — `QrRedirectController` `header()` + `exit`
    (refactor archi, basse priorité).

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
  (vert avant, vert après) — cf. #71, #72 et #79. Pour les fixes
  comportementaux : tests rouges prouvant les bugs — cf. #83
  (9 failures + 1 error, dont `DivisionByZeroError` réel).
- **Commit unique par ticket** : un commit = un ticket = une version.
- **Tag annoté** : `git tag -a vX.Y.Z -m "..."`.
- **Release GitHub** + **clôture issue** après chaque tag.
- **Mot-clé de clôture dans le commit** : ajouter `Closes #XX`
  (ou `Fixes #XX` / `Resolves #XX`) dans le corps du message de
  commit pour que GitHub ferme automatiquement l'issue au push
  sur `main`. Évite l'étape manuelle.
- **Travail direct sur `main`** : pas de branche feature pour les petits
  tickets. Branche feature (`feat/XX-...`) pour les tickets plus gros
  (comme #59 et #83) — merge via `--no-ff` sur main.
- **CHANGELOG + version.txt** mis à jour à chaque release.
- **`version.txt` sans newline final** (iso-comportement).
- **Hash dans `docs/REPRISE.md`** : la ligne de la **version courante**
  porte `<hash>`. Le hash réel est rempli **au ticket suivant**, au
  moment où l'on connaît la version figée. Évite un commit cosmétique
  par ticket.

---

## Commandes utiles

Tests (depuis la racine du projet) :

- `php vendor/bin/phpunit` — suite Unit/Domain (388 tests, cf.
  `defaultTestSuite="Unit"` dans `phpunit.xml`).
- `php vendor/bin/phpunit --testsuite Integration` — suite Integration
  (104 tests : 4 Integration Domain + 10 Integration Controller —
  le serveur HTTP est auto-démarré par WebServer, cf. commit `d1e0bfa`).
- `php vendor/bin/phpunit tests/Unit/Engine/FooTest.php` — un fichier.
- `php vendor/bin/phpunit --filter testMethodName` — une méthode.
- `php vendor/bin/phpunit --display-deprecations` — affiche le détail
  des dépréciations PHPUnit.

Serveur HTTP pour tests Integration Controller :

- **Aucun démarrage manuel nécessaire** : `WebServer::ensureRunning()`
  (dans `setUp()` de `AbstractControllerTestCase` et
  `AccessControlTest`) démarre `php -S localhost:8000 -t public
  public/router.php` si le port est libre, et l'arrête en fin de
  processus PHPUnit. Un serveur déjà lancé manuellement est réutilisé.
- ⚠️ Avec un serveur lancé manuellement : si tu modifies
  `.env.local.php` ou la base (rôle admin), **relance le serveur**
  pour qu'il recharge la session.

Base de données de test :

- `mysql -u root epiclub -e "SELECT id, username, role FROM utilisateur WHERE username='admin';"`
- `mysql -u root epiclub -e "SHOW CREATE TABLE acquisition"`
- `mysql -u root epiclub -e "SHOW CREATE TABLE acquisition_ligne"`
  (⚠️ `\G` n'est pas interprété en mode `-e` sous MySQL 8+ — utiliser
  le client interactif pour le format vertical.)
- `mysql -u root epiclub -e "DESCRIBE categorie"`

Pattern release Git :

- `git add <fichiers>`
- `git commit -m "type(#ticket): description" -m "Closes #XX"`
- `git push origin main`
- `git tag -a vX.Y.Z -m "vX.Y.Z — #ticket description"`
- `git push origin vX.Y.Z`

Pattern branche feature (tickets plus gros, ex. #83) :

- `git checkout -b feat/XX-description`
- …commits…
- `git push -u origin feat/XX-description`
- `git checkout main && git merge --no-ff feat/XX-description`
- puis tag + push comme ci-dessus.

Pattern non-régression (refactor iso-comportement) :

- Sauvegarder le fichier refactoré : `cp app/Domain/Xxx.php /tmp/Xxx.refactored.php`
- Restaurer la version HEAD~1 : `git checkout HEAD -- app/Domain/Xxx.php`
- Lancer le test Integration : doit être **VERT** (caractérisation)
- Restaurer le refactor : `cp /tmp/Xxx.refactored.php app/Domain/Xxx.php`
- Relancer : doit être **VERT** (non-régression)

⚠️ **Ne pas utiliser `git stash`** pour cette procédure : il stashe
**tous** les fichiers modifiés, y compris les fixtures et tests
nouveaux (non suivis exclus). Utiliser `cp` + `git checkout HEAD --
<fichier>` + `cp` retour. C'est ce qui a marché pour #71, #72 et #79.

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
  - **Factories de Managers (résolu en #80)** : `AcquisitionManager`
    et `AcquisitionLigneManager` exposent désormais des factories
    `fournisseurManager()`, `utilisateurManager()`, `categorieManager()`,
    surchargeables en test via `TestableAcquisitionManager` et
    `TestableAcquisitionLigneManager`. Le comptage de requêtes est
    désormais possible en Unit.
- **Compteur dans un closure PHPUnit** : les tableaux sont copiés par
  valeur à la sortie d'une méthode, alors que les objets sont passés
  par handle. Pour un compteur modifié dans un `willReturnCallback`
  et lu après l'appel testé, utiliser un `stdClass` ou un `ArrayObject`,
  **pas** un tableau. Détecté sur #80.
- **`final class`** : `getMockBuilder(X::class)->getMock()` échoue sur
  une classe `final`. Vérifier avec
  `grep -n "^final class" app/Domain/X.php` avant de mocker.
  `FournisseurManager`, `UtilisateurManager`, `CategorieManager` ne
  sont **pas** final — mockables directement. Détecté sur #80.
- **header() + `exit` dans un contrôleur** : non testable en Unit
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
- **`defaultTestSuite="Unit"` dans `phpunit.xml`** : la commande
  `php vendor/bin/phpunit` n'exécute **que** la suite Unit/Domain.
  Pour les tests Integration, ajouter `--testsuite Integration`.
  Détecté sur #79 (le nouveau test n'apparaissait pas dans
  `--list-tests`).
- **`DatabaseFixture`** : instancier avec `new DatabaseFixture($pdo)`
  (le constructeur prend un `PDO`). Les méthodes sont des méthodes
  **d'instance**, pas statiques. Cleanup via `cleanupTestData()` en
  `tearDown()`. Détecté sur #79.
- **`libelle` de `categorie` est UNIQUE** : `createCategorie()` doit
  y injecter un suffixe aléatoire (via `bin2hex(random_bytes(4))`)
  pour éviter les collisions entre tests. Détecté sur #79.
- **`AbstractControllerTestCase::promoteAdminToSuperAdmin()`** : la
  fixture promeut `admin` en `ROLE_SUPER_ADMIN` avant chaque test
  Integration Controller. Nécessaire pour les routes
  `/admin/system-settings` et `/admin/update`. Idempotent, robuste
  aux nouvelles bases de test. Ajouté en #81.
  ⚠️ Si tu modifies `.env.local.php` ou la base (rôle admin),
  **relance le serveur HTTP** (`php -S localhost:8000 -t public`)
  pour qu'il recharge la session. Sans serveur manuel, le serveur
  auto-démarré par `WebServer` recharge `.env.local.php` à chaque
  run de suite — rien à faire.
- **`AbstractManager::configProvider()`** : factory `protected`
  alignée sur `AbstractController::configProvider()` (#50a / #78),
  ajoutée en #82. Les managers qui ont besoin d'un `ConfigProvider`
  (ex : `EquipementManager::encryptionService()`) doivent passer
  par cette factory plutôt que d'instancier `new ConfigProvider(...)`
  en dur. Mockable en test.
- **Valeurs bindées, pas interpolées (#83)** : `JournalManager::getControlesClotures()`
  binde `:limit`/`:offset` via `bindValue` — les valeurs n'apparaissent
  **jamais littéralement** dans le SQL. Pour tester le bornage, capturer
  les appels `bindValue` via `willReturnCallback` (cf. Bloc D de
  `JournalManagerTest`). Une assertion sur la chaîne SQL resterait
  rouge même après fix.

---

## Contexte de la session précédente

- **#86** (v0.26.1) : Dette 4 soldée — dernier `@dataProvider`
  (`ControleControllerHandlersTest`, doublon avec l'attribut existant).
  `--display-deprecations` vide. 492 tests verts inchangés. Travail
  direct sur `main`.
- **#85** (v0.26.0) : FOREIGN KEY sur `acquisition` /
  `acquisition_ligne` (Dette 9 soldée). Migration
  `005_foreign_keys.sql` : 4 contraintes (RESTRICT sauf
  `acquisition_ligne.acquisition_id` en CASCADE). Gardes applicatives
  ajoutées : `CategorieManager::hasLignesAcquisition()`,
  `UtilisateurManager::hasAcquisitionsSaisies()` + refus propre dans
  les contrôleurs (avant : orphelins silencieux). Base locale migrée
  (004 + 005). Tests rouges d'abord (4 erreurs Unit). Pièges
  documentés : InnoDB rapporte RESTRICT implicite comme NO ACTION ;
  le test de caractérisation #79 « catégorie orpheline » réécrit en
  test du refus d'insertion. 4 tests Unit + 1 Integration ajoutés,
  1 réécrit. 388 tests Unit (953 assertions), 104 Integration (245).
  Travail direct sur `main`.
- **#84** (v0.25.4) : `UtilisateurManager` — projection publique
  `PUBLIC_FIELDS` + `findPublicId()` / `findPublicIds()` (Dette 8
  soldée). Les 3 hydratations de `redacteur` dans `AcquisitionManager`
  migrées : les vues Twig ne reçoivent plus `password` ni
  `reset_token`. Tests rouges d'abord (5 erreurs Unit + 3 erreurs
  mock), preuve par capture du SQL + test Integration base réelle.
  5 tests Unit + 1 test Integration ajoutés. 384 tests Unit (943
  assertions), 103 tests Integration (225 assertions). Travail direct
  sur `main`. Commits infra hors ticket cette session :
  `d1e0bfa` (WebServer auto) + `464b2b7` (REPRISE.md #83).
- **#83** (v0.25.3) : `JournalManager` — bornage `limit`/`page`
  (`limit < 1` → 10, `page < 1` → 1) + tri multi-colonnes
  (direction sur chaque colonne). Dette 7 soldée. Test rouge d'abord
  : 9 failures + 1 error (DivisionByZeroError réel sur limit=0).
  6 tests Unit ajoutés (Bloc D), Bloc B mis à jour. 379 tests Unit,
  913 assertions. 102 tests Integration. Première branche feature
  (`feat/83-journal-manager-limit-tri-multicolonnes`) + merge
  `--no-ff`.
- **#82** (v0.25.2) : `AbstractManager::configProvider()` +
  alignement `EquipementManager` (Dette 6 soldée). 2 tests Unit ajoutés.
  373 tests Unit, 877 assertions.
- **#81** (v0.25.1) : fix 7 failures `IndexControllerRootUrlTest`
  (promotion `admin` → `ROLE_SUPER_ADMIN` dans `AbstractControllerTestCase`).
  Cleanup `tests/Domain/` → `tests/Unit/Domain/`. Dette 3 soldée.
  Suite Integration 102 tests verts (vs 95/102). 371 tests Unit.
- **#80** (v0.25.0) : factories dans `AcquisitionManager` et
  `AcquisitionLigneManager` (Dette 10 soldée). Comptage de requêtes
  désormais testable en Unit. `AcquisitionManagerFindAllTest` renforcé
  (5 tests, valeurs + comptage), `AcquisitionLigneManagerFindIdTest`
  créé (3 tests). 371 tests Unit au total, 874 assertions.
- **#79** (v0.24.2) : `AcquisitionLigneManager::findId()` optimisé
  (2 → 1 requête via `CategorieManager::findIds()`). Ajout de
  `DatabaseFixture::createCategorie()` + cleanup des catégories
  `TEST-%`. 3 tests Integration de caractérisation. Dette 11 soldée.
  Comptage de requêtes non testable (Dette 10 réaffirmée comme
  prérequis). 368 tests Unit + 3 tests Integration Domain.
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
**🎯 Vague 14 en cours** (#68 à #85 livrés).
**Prochaine session** : reprendre les candidats de la Vague 14 —
`AbstractManager::findOneByCriteria()` générique (attention
`EquipementManager` `deleted_at IS NULL` + hydratation spécifique
`AcquisitionManager`), tests Unit sur les Managers non couverts,
ou Dettes 12/13 (`QrRedirectController`, basse priorité).