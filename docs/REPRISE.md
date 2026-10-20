# EPIClub — Fichier de reprise

**Dernière mise à jour** : 2026-10-20 (session — import Excel/CSV : sélection des lignes, catégories à créer, stock des lots, EPI à durée de vie limitée [beta.6])
**Version courante** : v1.0.0-beta.6
**Branche** : `main` (travail direct)

> **Note canal bêta** : l'endpoint GitHub `/releases/latest` ignore les
> pré-releases — une instance en canal `stable` ne voit donc pas les
> bêtas. Pour une instance de test : ajouter `UPDATE_CHANNEL=beta` dans
> `.env.local.php`. Le cache de détection dure 1 h
> (`var/tmp/update/latest_release.json[.beta]`) : supprimer ce fichier
> pour forcer une re-détection. Pendant la phase bêta, les releases
> GitHub ne sont plus marquées « prerelease » (la bêta vit dans le
> numéro de version) — sinon le code déjà déployé ne verrait pas la
> correction.

---

## État du projet

Application de gestion EPI (équipements de protection individuelle)
pour club d'escalade. PHP 8.5, Symfony components (HttpFoundation,
Mailer, Mime), Twig, MySQL, PHPUnit 11.

### Versions récentes

| Version | Ticket | Description | Commit |
|---|---|---|---|
| v1.0.0-beta.6 | — | Import Excel/CSV : lignes cochables (non conformes décochées) ; catégories à créer avec nom/code/durée de vie limitée, regroupement, catégorie imposée par ligne ; stock max / stock actuel des lots modifiables (migration 011) ; EPI à durée de vie limitée : date d'achat obligatoire, fin de vie par défaut 31/12 achat + 10 ans, dates modifiables + 20 tests | `<hash>` |
| v1.0.0-beta.5 | audit S1 S2 S4 S5 S7 F1 F2 | Upload image catégorie sûr (ImageUploader + .htaccess images) ; limitation des tentatives (migration 010) ; message reset unique ; redirection post-login stricte ; session resynchronisée à chaque requête + cookie durci ; archive de release complète (vendor/) + SHA-256 vérifié par l'updater ; updater met à jour public/ | `d82c891` |
| v1.0.0-beta.4 | audit C1 C1bis C3 C5 C12 P1 P2 P34 | Correctifs du premier rapport d'audit (bloquants + élevés) | `1e1ef8a` |
| v1.0.0-beta.3 | — | Canal de mise à jour paramétrable dans Paramètres système (sélecteur Stable/Bêta → UPDATE_CHANNEL, valeur invalide conservée) + factory environmentFilePath + 2 tests | `1945be5` |
| v1.0.0-beta.2 | — | Fix updater : pré-releases invisibles (/releases/latest les ignore) — canal UPDATE_CHANNEL stable/beta + cache par canal + affichage du canal sur la page Mise à jour + 7 tests | `4d01e5b` |
| v1.0.0-beta.1 | — | **1ʳᵉ bêta fonctionnelle de test** : scan QR intégré à la page (AJAX, sans quitter) + listes de catégories par activité dans le setup (escalade/spéléo/alpinisme) + 9 tests | `8a725f6` |
| v0.32.0 | — | Import : rattachement des catégories inconnues à une existante + lignes non conformes affichées en entier ; lots : nombre_initial plafonné, code sans LOT, badge « Lot : X/Y » liste + 9 tests | `5483764` |
| v0.31.1 | — | Import : détection auto de la ligne d'en-tête (fichier réel : en-tête ligne 4) + alias (EPI, stock théorique, HA- Périme) + sniffing délimiteur CSV + 4 tests | `792a37d` |
| v0.31.0 | — | Import v2 (analyse + mapping colonnes, lignes non conformes prévenantes, dates année seule, lot auto si nombre>1, groupement par date sans facture, mise en service) + case « Durée maximum d'utilisation » (catégorie/équipement) + 16 tests | `7b505bd` |
| v0.30.0 | — | Référencement automatique (modèle global YY/CAT/N, code catégorie) + import/export Excel/CSV d'acquisitions (groupes par facture auto-validées) + facture après coup + 43 tests | `ef5ec52` |
| v0.29.20 | — | Scan QR non connecté : vérification utilisateur avant deniAccess (?redirect= réellement émis) ; fixture deleteAcquisition robuste (FK controle_ligne) + 1 test Unit | `<hash>` |
| v0.29.19 | — | Login : ?redirect= honoré (retour sur le QR scanné après connexion) + garde anti open redirect + 3 tests Unit | `762aadb` |
| v0.29.18 | — | Ajout équipement → fiche contrôle directe (liste + scan QR) ; scan QR : branche contrôle réservée à ROLE_CONTROLLEUR + 2 tests Unit + 1 test Integration | `0918407` |
| v0.29.17 | #113+#114 | hasPending + idsDejaAjoutes calculés avant filtrage dans edit() (décision produit option A) + 3 tests Integration | `f00957f` |
| v0.29.16 | #112 | N+1 de edit() supprimés — findIds() batch Equipement/Emplacement + 4 tests Integration de caractérisation | `58df023` |
| v0.29.15 | #111 | ControleController::edit() découpé en handlers (iso-comportement, Priorité 4 audit) + 4 tests Unit | `cc2fbaf` |
| v0.29.14 | #110 | Enums morts supprimés (EquipementStatuts/EquipementEtats) + derniers $_ENV résiduels migrés (PUBLIC_PATH, APP_ENV) + 4 tests Unit | `87d6461` |
| v0.29.13 | #109 | Fixture de config — tests Unit indépendants du .env.local.php gitignored (ConfigFile + bootstrap PHPUnit) + 3 tests | `db1fb0e` |
| v0.29.12 | #108 | Dette 28 soldée — code mort setup/ (demo/ + checkbox @todo, footer version dynamique, mdp SMTP masqué) | `cca6e5a` |
| v0.29.11 | #108 | Dettes 25-27 soldées — durcissement installeur (chmod 0600, admin non re-exécutable, garde accès setup) | `8118f23` |
| v0.29.10 | #106+#107 | Dettes 23+24 soldées — setup sécurisé (doublon parser supprimé, CSRF installateur) + 6 tests Unit | `25d2868` |
| v0.29.9 | #99 | Dette 19 soldée — AcquisitionLineController migré Vague 11 (4 factories, 7 'new' retirés) + 6 tests Unit | `75b96f7` |
| v0.29.8 | #102 | Dette 21 soldée — AppUserAuthController return Response (2 bugs send()) + factory authenticator + 5 tests Unit | `f5ba114` |
| v0.29.7 | #100 | Dette 15 soldée — JournalController::index(Request) + query->get() (dernier $_GET de app/) + 3 tests Unit | `c37753d` |
| v0.29.6 | #101 | Dettes 16+17 soldées — ConfigProvider partout (systemSettings SMTP, PDO migrations) + 4 tests Unit | `3448799` |
| v0.29.5 | #104 | Dette 22 soldée — /guide réservé ROLE_USER (décision produit) + 2 tests Unit | `f3bd90b` |
| v0.29.4 | #103+#105 | Dettes 20+18 soldées (@deprecated actifs retirés, DatabaseConnection supprimé) + 2 templates email orphelins | `acfd84a` |
| v0.29.3 | #97-98 | Dettes 1+2 soldées — updateSystemSettings() + alias route, setAll() (1 écriture) | `d992ef4` |
| v0.29.1 | #96 | QrCodeReader supprimé (classe morte) — plus aucun header()+exit dans app/ | `9f2934f` |
| v0.29.0 | #95 | Dette 13 soldée — QrRedirectController en Response, actions testables | `7052801` |
| v0.28.3 | #94 | Dette 12 soldée — code mort QrRedirectController + fix flash perdu | `4220d61` |
| v0.28.2 | #93 | findAll utilisateur projeté sur PUBLIC_FIELDS — Dette 8 étendue | `5fa82d0` |
| v0.28.1 | #92 | 4 derniers Managers migrés (Club, Utilisateur, AcquisitionLigne, Equipement) — Vague 15 « écritures » terminée | `9055912` |
| v0.28.0 | #91 | filteredInsert()/filteredUpdate() génériques — 7 Managers migrés (Vague 15) | `cf3da50` |
| v0.27.3 | #90 | Caractérisation des save() — 11 tests (5 Managers) | `2c2d9ca` |
| v0.27.2 | #89 | Contrat findId() uniformisé (false → null) — divergence #88 corrigée | `5bd9392` |
| v0.27.1 | #88 | Couverture Unit Managers (Club, Controle, ControleLigne, Emplacement, EquipementControle) — 17 tests | `4ab5514` |
| v0.27.0 | #87 | findOneByCriteria générique dans AbstractManager — 7 copies factorisées | `4bd1d19` |
| v0.26.1 | #86 | Fin des @dataProvider — Dette 4 (chore PHPUnit) | `c579669` |
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

- **#90 — Caractérisation des `save()`.** 11 tests sur les 5 Managers
  de #88 : INSERT (sans id → `lastInsertId`), UPDATE (avec id →
  `_patch`), filtrage des champs non autorisés par `_patch`, défaut
  `hash_remarques = null` (ControleManager::_create). Helper partagé
  `makePdoCapturingInsertUpdate()` (SQL + params capturés) dupliqué
  dans les 5 fichiers — factorisation envisageable dans Support/.
  Contrat figé avant toute factorisation save() (pattern ALLOWED_FIELDS
  très proche entre Managers, candidat Vague 15).
- **#89 — Contrat `findId()` uniformisé.** `ControleManager::findId()`
  et `ControleLigneManager::findId()` retournaient `fetch()` brut
  (`false` absent), divergence caractérisée en #88. Désormais `?: null`,
  comme tous les Managers. Iso-comportement vérifié : tous les
  appelants testent `!$x` (falsy). Tests #88 mis à jour.
- **#88 — Couverture Unit des Managers non testés.** 17 tests de
  caractérisation (SQL capturé via mock PDO, pattern TestableXxx) :
  ClubManager (findParameters, singleton id=1), ControleManager
  (findAll JOIN utilisateur + ordre par défaut, findId, findActiveByUser),
  ControleLigneManager (findByControle, findByEquipement,
  findByControleAndEquipement, findId), EmplacementManager,
  EquipementControleManager. Verts du premier run (pas de refactor —
  caractérisation pure, filet pour les vagues futures).
  Divergence caractérisée ici puis corrigée en #89
  (`ControleManager::findId()` / `ControleLigneManager::findId()`
  retournaient `fetch()` brut → `false`).
- **#87 — `findOneByCriteria()` générique dans `AbstractManager`.**
  7 copies à l'identique dans les Managers (Utilisateur, Fournisseur,
  Emplacement, Categorie, EquipementControle + cas particuliers
  Equipement/Acquisition). Factorisation : méthode générique +
  factory `tableName()` (RuntimeException explicite si absente) +
  hook `baseWhereClause()` (filtre permanent). EquipementManager :
  soft delete migré sur le hook (iso-comportement). AcquisitionManager
  : surcharge avec hydratation fournisseur/redacteur en appelant
  `parent::findOneByCriteria()`. **Fix de bug documenté** :
  EquipementManager interpolait les clés de critères sans validation
  (injection SQL potentielle — non exploitable en l'état, clés en
  dur chez tous les appelants ; surface fermée par buildWhereClause).
  Piège rencontré : buildWhereClause préfixe ' WHERE ' (contrat #68)
  → combiné à baseWhereClause, le 2e WHERE devient AND.
  Test de caractérisation `AbstractManagerFindOneByCriteriaTest`
  (7 tests Unit, SQL exact capturé). Tests rouges d'abord (5 erreurs
  + 2 échecs). 395 tests Unit (965 assertions), 104 Integration.
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
- **496 tests** Unit/Domain verts, **1233 assertions**.
- **113 tests** Integration verts, **273 assertions** :
  - 11 Integration Domain (MySQL requis, dont `ForeignKeysIntegrationTest`
    et les 4 caractérisations `findIds()` #112).
  - 37 Integration Controller (serveur HTTP auto-démarré, dont les 3
    caractérisations #113/#114) — **plus de failures**.
  - 65 Security (`AccessControlTest`).
- Commande Unit : `php vendor/bin/phpunit`
  (`defaultTestSuite="Unit"` dans `phpunit.xml`).
- Commande Integration : `php vendor/bin/phpunit --testsuite Integration`.
- Le serveur HTTP est désormais **démarré automatiquement** par les
  tests (classe `Epiclub\Tests\Support\WebServer`, cf. commit
  `d1e0bfa`) : inutile de lancer `php -S` dans un autre terminal.
  Un serveur déjà en écoute est réutilisé, jamais arrêté par les tests.
- **Total avec MySQL actif** : 458 + 105 = **563 tests**.

✅ ~~7 tests Integration Controller en échec préexistant :
`IndexControllerRootUrlTest`~~ → **résolu en #81** (promotion
`admin` → `ROLE_SUPER_ADMIN` dans `AbstractControllerTestCase`).

---

## Prochaines cibles

### Vague 14 — ✅ complète (refactors Engine + perf SQL + rattrapage Vague 11)

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
- ✅ **#87** — `findOneByCriteria()` générique dans `AbstractManager`
  (candidat Vague 14). 7 copies factorisées, hook `baseWhereClause()`,
  fix injection SQL EquipementManager.
- ✅ **#88** — 17 tests de caractérisation sur les 5 Managers non
  couverts (Club, Controle, ControleLigne, Emplacement,
  EquipementControle). Divergence findId false/null documentée.
- ✅ **#89** — Uniformisation `findId()` (`?: null` partout) :
  divergence #88 corrigée, appelants vérifiés (tests falsy).
- ✅ **#90** — 11 tests de caractérisation des `save()` (INSERT /
  UPDATE / filtrage _patch / défaut hash_remarques).
- ✅ **#91** — **Vague 15 ouverte** : `filteredInsert()` /
  `filteredUpdate()` génériques dans `AbstractManager` (SQL dynamique,
  filtrage, défauts, hook `tableName()` #87, validation regex #68).
  7 Managers migrés (Acquisition, Categorie, Controle, ControleLigne,
  Fournisseur, Emplacement, EquipementControle) ; `tableName()` ajouté
  à Controle et ControleLigne. Changement de comportement documenté :
  clés supplémentaires ignorées (au lieu de PDOException) sur les
  ex-« bruts », mise à jour partielle possible sur `_patch`. 4 fixtures
  #90 corrigées (clés arbitraires → vraies colonnes). 9 tests Unit
  sur les helpers.

### Vague 15 — ✅ écritures terminées (#91 + #92)

- ✅ **#91** — `filteredInsert()` / `filteredUpdate()` dans
  `AbstractManager` ; 7 Managers migrés (cf. section Vague 14 ci-dessus
  pour l'entrée détaillée).
- ✅ **#92** — 4 derniers Managers migrés : `UtilisateurManager`
  (alignement, son SQL dynamique faisait doublon), `ClubManager`
  (`id=1` forcé en `_patch` — singleton), `AcquisitionLigneManager`
  (`equipements_generes` forcé à 0, `regrouper_en_lot ?? 0`, traduction
  duplicate key conservée), `EquipementManager` (9 coercitions `??`
  conservées). `tableName()` ajouté à Club et AcquisitionLigne.
  7 tests de caractérisation `save()` ajoutés (Equipement,
  AcquisitionLigne). **Tous les `_create`/`_patch` passent par les
  helpers #91.**

Candidats suivants (par ordre de pertinence) :
  - ~~Hydratation `findAll` restée sur `SELECT *`~~ → **#93 soldé** :
    `UtilisateurManager::findAll()` projeté sur `PUBLIC_FIELDS`.
    Audit des autres findAll : toutes les colonnes sont consommées
    (templates / hydratation PHP), SELECT * justifié — rien à faire.
  - ~~**Dette 12**~~ → **#94 soldé** (code mort supprimé + fix flash).
  - **Dette 13** — `QrRedirectController` `header()` + `exit`
    (refactor archi, basse priorité).
  - Nettoyage des `error_log()` de debug dans `QrRedirectController::
    generateQr()` (cosmétique).

---

## Dettes accumulées

Ces points ne sont **pas bloquants** mais à traiter dans des tickets
séparés quand le temps le permet.

### Dette 1 — ✅ SOLDÉE (#97, v0.29.2)

- `IndexController::updateSmtp()` renommée `updateSystemSettings()` ;
  route canonique `POST /update-system-settings`.
- L'ancienne `/update-smtp` est conservée en alias (double mapping
  POST vers la même action — une redirection perdrait le corps POST).
  Twig mis à jour ; 1 test Integration ajouté sur la route canonique.

### Dette 2 — ✅ SOLDÉE (#98, v0.29.3)

- `EnvironmentFileParser::setAll(array $values)` ajouté : une seule
  réécriture du fichier pour N clés (clés en majuscules, comme set()).
- `IndexController::updateSystemSettings()` migré : 3-4 écritures → 1.
- `_dump()` passé protected : une sous-classe de test compte les
  écritures (test : setAll = 1 dump pour 4 clés, set inchangé).

### Dette 12 — ✅ SOLDÉE (#94, v0.28.3)

- `getControleById()` / `getUtilisateurById()` étaient du **code mort**
  (zéro appel, méthodes privées) : supprimées, suppression figée par
  un test.
- Bonus : fix documenté d'`addFlash()` — `method_exists($session,
  'addFlashBag')` toujours faux + écriture dans `$_SESSION['flash_bag']`
  (clé jamais lue par les templates) : les flashs QR étaient perdus.
  Aligné sur `getFlashBag()->add()`.
- Reste connu (non bloquant) : ~~`error_log()` de debug dans
  `generateQr()`~~ → nettoyés en #95.

### Dette 13 — ✅ SOLDÉE (#95, v0.29.0)

- Les 7 actions de `QrRedirectController` retournent désormais des
  objets `Response` / `RedirectResponse` / `JsonResponse` ; le
  `->send()` est fait par le dispatcher (`public/index.php`) — aucune
  adaptation nécessaire, il attendait déjà une Response.
- **Actions désormais testables en Unit** : 12 tests
  (`QrRedirectControllerActionsTest`) — aiguillages de `redirect()`,
  400/404 JSON de `saveQr`/`apiGenerateQr`, 404 de `generateQr` et
  `choicePage`.
- Extraction de l'id factorisée (`extractId()`, 7 copies) ; factory
  `utilisateurManager()` retirée (ne servait qu'au code mort #94) ;
  `error_log()` de debug nettoyés.
- Reste connu : ~~`QrCodeReader::read()` (avec
  `$outputDirectly = true`) utilise encore `header()` + `exit`~~ →
  **#96 : classe entièrement morte, supprimée**. Plus aucun
  `header()`/`exit` dans `app/` hors `bootstrap.php` (garde-fou
  d'installation + `dd()`), qui précède le dispatcher.

### Dettes 15-22 — audit externe du 2026-10-16 + revue croisée

Un audit externe (vérifié contre le code courant le 2026-10-17) a
détecté 8 dettes non documentées ci-dessous, plus des découvertes
annexes. ⚠️ L'audit parlait de « v0.25.3 » mais visait un état plus
ancien : toutes ont été re-vérifiées une par une sur v0.29.3 avant
d'être tracées ici. La revue croisée a par ailleurs réfuté deux
allégations : les dettes 4 et 12 **sont** soldées (#86, #94), et le
« Symfony 7.3 » est exact (composer.json `^7.3`).

#### Dette 15 — ✅ SOLDÉE (#100, v0.29.7)

- `index()` sans `Request` en signature ; lisait `$_GET['controleur']`,
  `annee`, `order_by`, `order_dir`, `limit`, `page` ; garde
  `if (!$this->isGranted(...))` au lieu de `deniAccessUnlessGranted()`.
- Migré : `index(Request $request)` + `$request->query->get()` (valeurs
  et défauts strictement identiques ; dispatcher passe toujours le
  Request). Dernier `$_GET` de `app/` — il n'en reste aucun en lecture
  (un seul commentaire dans AcquisitionController mentionne $_POST).
- **Sous-point de l'audit écarté, documenté** : la garde
  `if (!isGranted) redirectTo(/se_connecter)` est CONSERVÉE. Ce n'est
  pas de l'incohérence : c'est le pattern de `voir()`/`pdf()` et
  d'`IndexController::index()` (redirect UX anonyme vers le login).
  `deniAccessUnlessGranted` renverrait vers `/` avec flash — régression
  UX sans gain de sécurité. Pas un fix de bug → iso-comportement prime.
- 3 tests Unit : `JournalControllerIndexTest` (rouge d'abord : filtres
  ignorés du Request avant migration).

#### Dette 16 — ✅ SOLDÉE (#101, v0.29.6)

- `systemSettings()` lisait `getenv('MAILER_DSN') ?: $_ENV[...]` (lignes
  ~52-54) au lieu de `ConfigProvider`, alors que le même fichier utilise
  `ConfigProvider` ailleurs (ligne ~64).
- **Fix de bug documenté** : getenv lit l'environnement du PROCESSUS,
  qui pouvait afficher des valeurs différentes de `.env.local.php`
  (fichier réellement utilisé par MailerService et bootstrap). Lecture
  unifiée via `configProvider()` (factory mockable), garde
  fichier-absent/illisible conservée (chaînes vides). La lecture
  ROOT_URL (#59) est fusionnée dans le même bloc (un seul provider).
- 2 tests Unit : `IndexControllerSystemSettingsTest`.

#### Dette 17 — ✅ SOLDÉE (#101, v0.29.6)

- `perform()` construisait le DSN depuis `$_ENV['DB_HOST']` etc. (lignes
  ~181-185), contournant ConfigProvider.
- Extraction des factories protégées `databasePdo()` / `databaseDsn()`
  alimentées par `configProvider()`. Iso-comportement strict (bootstrap
  alimente `$_ENV` depuis le même fichier) ; clé absente → '' sans
  warning (avant : warning PHP + ''), toujours dans le try/catch de
  perform(). Bonus : construction du DSN testable en Unit.
- 2 tests Unit : `AppUpdateDatabasePdoTest` (connexion court-circuitée).

#### Dette 18 — ✅ SOLDÉE (#105, v0.29.4)

- `Engine/DatabaseConnection.php` : classe legacy (dernier `$_ENV`
  direct pour PDO), **zéro appelant** hors d'elle-même (vérifié par
  grep exhaustif). Supprimée, figée par
  `tests/Unit/Engine/DatabaseConnectionRemovedTest.php`.

#### Dette 19 — ✅ SOLDÉE (#99, v0.29.9)

- **Seul contrôleur** avec `new AcquisitionLigneManager()`,
  `new CategorieManager()`, `new AcquisitionManager()`,
  `new AcquisitionProcess()` en dur (non mockable). La Vague 11
  était annoncée complète — elle l'est réellement désormais.
- 4 factories `protected` extraites ; `validator()` (private) passe
  par `acquisitionProcess()`. Grep exhaustif post-migration : tous
  les `new XxxManager()/Process` de `app/Controller/` vivent dans des
  corps de factory (restent des utilitaires hors scope : ZipArchive,
  Dompdf, QrCodeGenerator, Spreadsheet — pas de dette déclarée dessus).
- Iso-comportement strict. 6 tests Unit : `AcquisitionLineControllerTest`
  (rouge d'abord : 5 échecs prouvant les mocks ignorés avant migration).

#### Dette 20 — ✅ SOLDÉE (#103, v0.29.4)

- `@deprecated Use this->show()` sur `UtilisateurController::edit()`,
  pourtant servie par la route active `/admin/utilisateurs/nouveau`.
  Même contradiction sur `delete()` (route active, contrôlée
  ROLE_ADMIN). Annotation remplacée par de vrais docblocks. Aucun
  impact routage : l'ancienne route `utilisateur_update` était déjà
  désactivée (commentée dans routes.php).

#### Dette 21 — ✅ SOLDÉE (#102, v0.29.8)

- `login()` et `logout()` faisaient `$response->send()` puis laissaient
  le code continuer (bug latent) ; `new AppUserAuthenticator($this->session)`
  en dur sans factory.
- **Fix de bug documenté n°1** : login() rendait le formulaire EN PLUS
  de la redirection (double sortie, « headers already sent »).
- **Fix de bug documenté n°2** : logout() retournait null → send() sur
  null dans le dispatcher (Error fatale après envoi du header — la
  déconnexion « marchait » par accident).
- Factory `authenticator()` extraite (pattern Vague 11) ;
  `$_SERVER['REMOTE_ADDR']` → `$request->server->get()` (dernière
  superglobale de lecture dans les contrôleurs) ; `: Response` ajoutés.
- 5 tests Unit : `AppUserAuthControllerTest`. Plus aucun `->send()`
  dans `app/` hors commentaire (pattern #95 complet).

#### Dette 22 — ✅ SOLDÉE (#104, v0.29.5)

- `GuideController::index()` sans aucune garde. Le lien est dans
  `_layout.twig`, qui sert aussi `user_forgot_password.twig` (publique,
  hors connexion) → le guide était atteignable anonymement, et
  AccessControlTest le figeait comme « seule ressource publique ».
- **Décision produit 2026-10-17** : réservé aux connectés.
  `deniAccessUnlessGranted('ROLE_USER')` ajouté, lien de nav masqué
  pour les anonymes, `tests/public_routes.php` mis à jour, test
  anonyme remplacé (302 → /), 2 tests Unit ajoutés
  (`GuideControllerTest` + `TestableGuideController`).

### Découvertes annexes de l'audit (hors numérotation)

- **Enums morts** : ~~`EquipementStatuts` (pluriel, string, legacy) et
  `EquipementEtats` — zéro usage en dehors de leur propre fichier~~
  **supprimés en v0.29.14 (#110)**. `EquipementStatut` (singulier, int,
  utilisé par `EquipementController`) conservé.
- **Templates orphelins** : `templates/email/account_validation.twig`
  et `templates/email/user_reset_password.twig` — zéro `render()`
  (vérifié). Seul `email/reset_password.twig` est utilisé, il étend
  `email_layout.twig` (conservé). **Supprimés en v0.29.4** (même
  commit que #105).
- **`setup/` hors périmètre** : l'installeur (écrit le `.env` avec les
  credentials BDD, crée le compte admin) n'a jamais été audité —
  permissions du fichier `.env`, échappement des saisies, suppression
  post-install. Zone d'ombre déclarée, à planifier en audit dédié.
  → **Audit réalisé le 2026-10-17**, voir section ci-dessous
  (« Audit setup/ — dettes 23+ »).
- **`ControleController::edit()`** — ~~~400 lignes, N+1 connus listés en
  Priorité 4 de l'audit~~ **découpé en handlers en v0.29.15 (#111)** —
  iso-comportement, +4 tests Unit.
  ~~Reste ouvert : les findId() par équipement/catégorie/emplacement (N+1
  figés par `ControleControllerEditManagersTest`)~~ **N+1 supprimés en
  v0.29.16 (#112)** — `findIds()` batch sur Equipement/Emplacement,
  `enrichLignesWithEquipements()` et `enrichEquipementsWithRelations()`
  réécrits (iso-comportement, +4 tests Integration de caractérisation).
  Rendu GET edit() : requêtes désormais constantes (7 max) quelle que
  soit la taille des listes.
  ~~Reste ouvert : l'écart `hasPending`~~ **#113 + #114 soldés en
  v0.29.17 (2026-10-18)** — analyse et décision ci-dessous.

### #113 + #114 — calculs déplacés avant filtrage dans `edit()` — ✅ SOLDÉS (v0.29.17)

*Constat* : dans `edit()`, `$hasPending` était calculé après
`filterLignes()` (statut + recherche), alors que la liste (`index()`)
et la garde serveur `cloturer()` le calculent sur **toutes** les
lignes. Impact #113 : UX uniquement — un filtre masquant les lignes
`a_controler` activait le bouton « Clôturer », qui échouait au POST
(la garde serveur tenait, aucun trou d'intégrité). Finding #114 (même
famille, intégrité cette fois) : `idsDejaAjoutes` aussi calculé après
filtrage → un équipement déjà ajouté dont la ligne était masquée
réapparaissait dans « équipements disponibles » ; `addEquipement()`
insère sans garde et `controle_ligne` n'a pas d'UNIQUE(controle_id,
equipement_id) → doublons de lignes possibles.
*Décision produit (2026-10-18)* : **option A** — les deux calculs
déplacés avant filtrage, alignés sur `index()` et `cloturer()`.
*Fix* : 2 lignes déplacées dans `edit()` ; garde `addEquipement()` et
contrainte UNIQUE non retenues (fix minimal).
*Tests* : 3 Integration HTTP (`ControleControllerEditPendingTest`) —
bouton désactivé malgré filtre, clôture proposée sans ligne pendante,
équipement ajouté non re-proposé malgré filtre (+ témoin). Vérifiés
rouges sans le fix, verts avec. Fixture : `createControleLigne()` +
cleanup des contrôles TEST- et de leurs lignes AVANT les acquisitions
(FK `controle_ligne → club_equipement` sans CASCADE).
- **Accès `$_ENV` résiduels** (constatés en #101) :
  ~~`AbstractController` (~ligne 184, `PUBLIC_PATH`) et
  `TwigRenderer` (~ligne 25, `APP_ENV` dev)~~ **migrés en v0.29.14
  (#110)** — les deux lisent désormais ConfigProvider (`ConfigFile::
  defaultPath()`), repli `$_SERVER['PUBLIC_PATH']` conservé. Tous les
  `$_ENV` de *config métier* sont désormais migrés (hors bootstrap,
  composition root légitime).

### Audit `setup/` — 2026-10-17 (dettes 23+)

Périmètre : 13 fichiers PHP, ~950 lignes (install.php, requierements.php,
includes/, steps/, demo/). Méthode : lecture intégrale + vérifications
croisées avec `app/bootstrap.php` et `app/Engine/EnvironmentFileParser`.

**Points sains (vérifiés)** :
- Validation regex stricte host/base/user en step_dbms ; refus d'installer
  sur une base non vide ; mot de passe jamais ré-affiché en cas d'erreur.
- Migrations exécutées par `MigrationManager` (aucun SQL à la main).
- Compte admin : `password_hash`, 12 caractères min, double saisie,
  rôle SUPER_ADMIN documenté. Requêtes préparées partout (managers).
- `SECRET_KEY` (32 octets aléatoires) et `SETUP_COMPLETE` écrits via
  `var_export` (step_admin, step_final). Bootstrap : fallback propre qui
  repose SETUP_COMPLETE si la base contient déjà des utilisateurs.
- `install.php` : whitelist stricte des étapes (`in_array`) — pas de LFI.
- XSS : sorties systématiquement `htmlspecialchars`.

#### Dette 23 — ✅ SOLDÉE (#106, v0.29.10)

- `setup/includes/EnvironmentFileParser.php` redéclare
  `Epiclub\Engine\EnvironmentFileParser` (même namespace !) et est
  chargé en premier par `step_1.php` (`require_once`) → **c'est la
  version legacy pré-#49 qui gagne** pour toute la session d'install.
- Son `_dump()` écrivait `'$key' => '$value'` **sans échappement** :
  un `DB_PASS` contenant `'` ou `\` produisait un `.env.local.php`
  **invalide** (site hors service) ou permettait d'injecter des clés
  arbitraires dans un fichier PHP exécuté.
- Correctif : fichier supprimé, `require_once` retiré de step_1 ;
  l'installeur utilise la classe app (autoloadée, échappement
  var_export #49, constructeur `?string $filePath = null` compatible
  avec les appels existants). step_1 : `$env->load()` (absent de la
  classe app) remplacé par un include direct avec garde is_array.
- 2 tests Unit : `SetupLegacyParserRemovedTest` (suppression figée +
  round-trip d'un mot de passe hostile).

#### Dette 24 — ✅ SOLDÉE (#107, v0.29.10)

- Aucun des formulaires setup (step_1, dbms, admin, smtp, club) ne
  vérifiait de jeton. Pendant la fenêtre d'installation (l'installeur
  est servi sur TOUTES les URLs par bootstrap), un site tiers pouvait
  forger les POST — en particulier **créer le compte SUPER_ADMIN avec
  les identifiants de l'attaquant** (détournement d'installation).
- Correctif : helpers `setup_csrf_token()` / `setup_csrf_field()` /
  `setup_csrf_is_valid()` (setup/includes/helpers.php, jeton unique de
  session, hash_equals) ; champ caché + validation ajoutés aux 5
  étapes — le blocage s'appuie sur le gate `empty($errors)` existant
  de chaque étape (iso-structure). Smoke test manuel : les 5 étapes
  rendent bien le champ (test de fumée en sous-processus, fichier
  .env.local.php créé par step_1 supprimé après).
- 4 tests Unit : `SetupCsrfHelpersTest` (génération stable, champ,
  acceptation/rejet).

#### Dette 25 — ✅ SOLDÉE (#108, v0.29.11)

- `step_1.php` créait le fichier avec `chmod($envFile, 0664)` → lisible
  par tous les utilisateurs locaux (credentials BDD en clair).
- Correctif : `chmod($envFile, 0600)` + commentaire justificatif. Vérifié
  par grep du source (smoke test step_1 interdit après l'incident de
  la session #106/#107 : tout run de step_1 réécrase le `.env.local.php`
  de dev non commité).

#### Dette 26 — ✅ SOLDÉE (#108, v0.29.11)

- Chaque POST insérait un NOUVEL admin SUPER_ADMIN (aucune garde) et
  régénérait SECRET_KEY (ré-écriture silencieuse : données chiffrées
  existantes rendues indéchiffrables en re-run post-usage).
- Correctif : pré-garde en tête du bloc d'insertion (`new
  UtilisateurManager()` + `findAll()` non vide → erreur « Un compte
  administrateur existe déjà. Pour modifier le compte principal,
  connectez-vous à l'application. ») ; SECRET_KEY n'est régénéré que si
  `empty($config['SECRET_KEY'])` (`$keyHex = null` par défaut, généré
  conditionnellement, `$keyGenerated` aligné).
- Smoke test /tmp (CSRF seedé en session, POST valide) : message
  affiché, formulaire réaffiché, **aucune ligne insérée** dans
  `utilisateur` (base de dev, 3 comptes avant/après).

#### Dette 27 — ✅ SOLDÉE (#108, v0.29.11)

- step_final AFFICHAIT « supprimez setup/ » mais rien n'empêchait de
  relancer l'installeur si le docroot inclut le dossier projet.
- Correctif : garde en tête d'`install.php` (juste après le chargement
  de `.env.local.php`) : si `!empty($_ENV['SETUP_COMPLETE'])` → HTTP 403
  + exit. Smoke test /tmp : code 403 confirmé via shutdown handler et
  message « EPIClub est déjà installé. Supprimez le dossier setup/ de
  votre serveur. ». Le flux normal n'est pas affecté (le bootstrap ne
  requiert install.php que si l'install est incomplète).

#### Dette 28 — ✅ SOLDÉE (#108, v0.29.12)

- `setup/demo/` (CSV + images, 1,3 Mo) **jamais utilisé** : la checkbox
  « Installer les données par défaut » de step_club contenait un
  `@todo` mort → la case ne faisait rien. Décision (audit) : retirer
  plutôt que livrer l'import — dossier supprimé, checkbox + handler +
  note de bas de formulaire retirés de step_club.
- `setup/includes/footer.php` affichait « Epiclub 0.9.3 (beta) »,
  version fantaisiste vs version.txt. Correctif : version lue
  dynamiquement dans `version.txt` (`__DIR__/../../version.txt`,
  fallback 0.0.0). Le même « 0.9.3 (beta) » figé traînait dans
  `templates/user_login.twig` → remplacé par `{{ app_version }}`, le
  global déjà exposé par TwigRenderer (convention de `_layout.twig`).
- step_smtp ré-affichait le mot de passe saisi (`value="<?= … $smtp
  ['password'] ?>"`) après un POST en échec — incohérent avec step_dbms.
  Correctif : champ rendu vide + clé `password` retirée du tableau de
  ré-affichage.
- Smoke tests /tmp : checkbox absente du HTML de step_club, footer
  « Epiclub 0.29.11 (beta) » rendu (fallback 0.0.0 vérifié quand
  version.txt est absent), champ password SMTP vide.
- Suites : 485 Unit (1208 assertions) + 106 Integration (250
  assertions) — baseline inchangée.

#### Dette tests — ✅ SOLDÉE (#109, v0.29.13) : fixture de config, tests Unit indépendants de `.env.local.php`

- 68 tests Unit lisaient structurellement le `.env.local.php` gitignored :
  le constructeur d'`AbstractManager` incluait littéralement
  `{projet}/.env.local.php` et ouvrait un PDO réel, et les factories
  `configProvider()` d'`AbstractManager`/`AbstractController` faisaient de
  même via `new ConfigProvider(__DIR__ . '/../../.env.local.php')`.
  Mesuré : 68 erreurs / 485 tests quand le fichier est renommé.
- Correctif : `Epiclub\Engine\ConfigFile::defaultPath()` — résolution
  unique qui honore `EPICLUB_CONFIG_FILE` (définie par le nouveau
  `tests/bootstrap.php`, référencé dans `phpunit.xml`) et retombe sur le
  vrai `.env.local.php` en production. Fixture commitée
  `tests/Fixtures/env.test.php` enrichie des clés DB (127.0.0.1, epiclub,
  root, '' — iso-comportement : les tests se connectaient déjà à cette
  base via le fichier réel).
- Non touché volontairement : `IndexController` (lecture/écriture
  explicite du fichier réel pour l'admin config), `app/bootstrap.php`,
  et la suite Integration (lit le vrai fichier par chemins explicites).
- Vérifié : 488 Unit (1215 assertions) VERTS avec `.env.local.php`
  renommé, puis avec ; 106 Integration (250 assertions) inchangés.
- 3 tests Unit nouveaux : `ConfigFileTest` (seam active, fixture
  chargeable, construction d'un Manager sans le fichier gitignored).
  Note : reste une dépendance live-MySQL pour ces constructions — la
  rendre hermétique (PDO simulé partout) est un chantier à part.

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

- `php vendor/bin/phpunit` — suite Unit/Domain (458 tests, cf.
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
  ⚠️ **La SUITE DE TESTS en dépend** : ~68 tests Unit (tout manager
  touchant `ConfigProvider` via `AbstractManager`) plantent si le
  fichier est absent (« Fichier de configuration .env.local.php non
  trouvé »). Ne JAMAIS le supprimer — en particulier lors de smoke
  tests de `setup/` : step_1 le CRÉÉ si absent (risque de le détruire
  ensuite en « nettoyant »). Incident réel du 2026-10-17 (#107) :
  suppression accidentelle → 68 erreurs + re-création manuelle
  (nouvelle SECRET_KEY → les 99 `hash_remarques` chiffrés antérieurs
  sont désormais indéchiffrables, données de dev uniquement).
  Fragilité structurelle connue : ces tests devraient utiliser un
  fixture de config plutôt que le vrai fichier (candidat ticket
  testabilité).
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

- **Scan QR dans la page** (2026-10-19, décision produit) : page
  `/controles/scanner` (menu Contrôles → « Scanner un équipement ») +
  endpoint AJAX `POST /controles/scanner/ajouter`
  (`ControleScanController`). Bibliothèque **html5-qrcode 2.3.8
  embarquée** (`public/assets/js/html5-qrcode.min.js`, 375 Ko, MIT —
  pas de CDN : terrain sans réseau). JS : `window.isSecureContext`
  vérifié (caméra = HTTPS/localhost uniquement, message explicite
  sinon), anti-doublon 3 s + verrou requête, bip WebAudio, liste des
  scannés, « Terminer » → fiche contrôle. Endpoint : statuts
  `ajoute` / `deja` (findByControleAndEquipement AVANT addEquipement,
  qui retourne true même en doublon) / `hors_controle` (+ redirect
  fiche équipement) / `introuvable` / 400 id invalide / 403 CSRF.
  ⚠️ Hiérarchie des rôles par niveaux (Session::ROLE_LEVELS) :
  SUPER_ADMIN ≥ CONTROLLEUR. Tests : 5 Integration
  (`ControleScanControllerTest`).
- **Release 1.0.0-beta.1** demandée par l'utilisateur : première
  bêta fonctionnelle de test (tag `v1.0.0-beta.1`, `--prerelease`).

- **Setup — listes préparées de catégories par activité** (2026-10-19,
  décision produit) : nouvelle étape `categories` du setup entre
  `club` et `final` (`step_club` redirige vers elle). Presets dans
  `setup/includes/categories_presets.php` (Escalade : 12 catégories
  fournies par l'utilisateur — HAR/DEG/ASS/MOU/COR/RAP/OUT/CAS/FIC/ANN/
  SAE/MOS avec est_epi et duree_max_utilisation ; Alpinisme = même base
  + PIO/CRA/CRG/BRO/SKI/CHA/ARV (piolet, crampons, corde de randonnée/
  glacier, broche à glace, ski de randonnée, chaussures de ski, ARVA —
  décision utilisateur) ; Spéléologie = 16 catégories — CAS/HAR/
  BLO/BLP/DES/ETR/LON/COR/FIC/ANN/MOU/MOS/ECL/COM/SAC/OUT, réglages
  EPI/durée max à faire valider par l'utilisateur). « Spéléologie »
  ajouté aux activités de `step_club.php`. L'étape lit l'activité du club via
  `ClubManager::findParameters()`, affiche un tableau à cases cochées
  (toutes par défaut), insertion via `CategorieManager::save()`,
  **idempotente** (skip si libellé déjà présent), lien « Passer » vers
  final. Lien symbolique activité inconnue → liste Escalade.
  ⚠️ Ne concerne que les NOUVELLES installations (SETUP_COMPLETE) —
  pour une base existante, utiliser Administration → Catégories ou le
  rattachement à l'import. Tests : 557 Unit (dont 4 garde-fous sur les
  presets : complétude, codes/libellés uniques), 63 Integration.

- **Lignes non conformes complètes + lots (nombre initial)** (2026-10-19,
  décisions produit) :
  - à l'analyse, chaque ligne non conforme est affichée avec son
    **contenu complet** (`lignes_non_conformes[].donnees`) ;
  - **lot** : migration `008_nombre_initial` (`club_equipement
    .nombre_initial` INT NOT NULL DEFAULT 1, backfill = nombre pour les
    lots existants) ; le code technique d'un lot ne contient **plus le
    suffixe `-LOT`** (`-001` comme une unité) ; fiche équipement :
    nombre initial en lecture seule + **nombre actuel modifiable entre
    1 et nombre_initial** (plafond vérifié serveur dans
    `EquipementController::edit`, clé d'erreur `nombre`) ; fiche détail
    : « X restants sur Y initiaux » ; liste des équipements : badge
    « Lot : X/Y » dans la colonne Nom ; `EquipementManager` : ALLOWED_
    FIELDS += nombre_initial, défaut _create = nombre saisi.
  Tests : 553 Unit, 63 Integration (nouveau fichier
  `EquipementControllerEditLotTest`, 5 tests).

- **Import — rattachement des catégories inconnues** (2026-10-19,
  décision produit) : à l'étape d'analyse, chaque catégorie du fichier
  non résolue (ni code ni libellé) propose un select « Créer la
  catégorie » (défaut) / « Rattacher à : … » (catégories existantes).
  Implémentation : `analyser()` collecte `categories_fichier`
  (distinctes, pur — pas de DB), `enrichirCategories()` (DB : résolution
  + liste existantes) appelé par le contrôleur, `import()` reçoit
  `$choixCategories` (valeur → 'nouvelle'|id, id vérifié en base,
  sinon résolution normale), `trouverCategorie()` factorisé. Template :
  section « Catégories du fichier » (✓ résolue / select sinon).
  Tests : 553 Unit, 58 Integration (dont rattachement end-to-end).
  ⚠️ La base de dev a été **réinstallée** par l'utilisateur le
  2026-10-19 (user `jean` SUPER_ADMIN, 6 vraies catégories) : compte
  `admin`/`adminadmin` recréé pour la fixture Integration, et
  **SECRET_KEY régénérée** dans `.env.local.php` (absente après
  réinstall — 2 tests skippaient, l'app aurait planté sur le chiffrement
  des remarques de contrôle).

- **Import — adaptation au fichier d'inventaire réel** (2026-10-19,
  suite v0.31.0) : le fichier réel du club (« EPI au 25 juin 2026.xlsx »)
  a l'en-tête en **ligne 4** (titre fusionné + lignes vides au-dessus)
  et des en-têtes spécifiques (`EPI` = désignation, `ACHAT`, `HA-
  Périme`, `stock théorique` = nombre). Ajouts : `detecterLigneEntete()`
  (≥ 2 alias dans les 10 premières lignes, sinon première non vide,
  lignes au-dessus ignorées et signalées), alias supplémentaires
  (`epi`, `stocktheorique`/`stock`, `haperime`/`perime`), et **sniffing
  du délimiteur CSV** sur les 20 premières lignes non vides
  (l'inférence PhpSpreadsheet échouait si les premières lignes n'ont
  pas le délimiteur). Simulation sur le fichier réel : en-tête ligne 4
  détectée, 53 lignes, 4 non conformes (32, 53, 54, 65 — sans
  désignation). Tests : 552 Unit, 57 Integration.

- **Import v2 + durée maximum d'utilisation** (2026-10-19, sans ticket,
  cible v0.31.0). Décisions produit validées au fil de la session :
  - **case « Durée maximum d'utilisation »** : booléen sur `categorie`
    (défaut) ET `club_equipement` (hérité à la génération, modifiable
    dans la fiche) — certains matériels ont une durée limitée par le
    fabricant (~10 ans), d'autres non tant que l'usure est acceptable.
    Pas de durée en années : la date fait foi ;
  - **date de péremption = `club_equipement.date_fin_utilisation`**
    existant (pas de nouveau champ équipement ; colonne
    `acquisition_ligne.date_peremption` reportée dessus à la génération) ;
  - **date de mise en service = `facture_date`** à l'import
    (`EquipementManager::marquerEnServiceParAcquisition`, n'écrase que
    les NULL ; saisie manuelle inchangée) ;
  - dates d'import : année seule → 1er janvier (achat) / 31 décembre
    (péremption) ; date d'achat vide → date courante ; péremption vide →
    vide ; séries Excel numériques reconnues ;
  - catégorie d'import résolue par **code** (ex. `TCI`), sinon libellé,
    sinon créée (libellé tronqué 32, est_epi=1) ;
  - **`nombre > 1` ⇒ lot automatique** ; colonne `lot` SUPPRIMÉE du
    format (rupture) ; une ligne = un équipement = une référence ;
  - import en **2 étapes** : analyse (correspondance colonnes↔champs
    devinée par en-tête, modifiable, colonnes ignorables ; aperçu 5
    lignes ; **lignes non conformes présentées AVANT import — « seront
    ignorées »**) puis confirmation → rapport (qui les liste aussi) ;
  - **groupement sans facture** : par date d'achat normalisée →
    référence synthétique `IMP-AAAA-MM-JJ`, placeholder « facture à
    importer » ; le PDF téléversé ensuite peut regrouper toutes les
    factures des équipements liés ; fournisseur vide → « Divers » ;
    plusieurs fournisseurs dans un groupe par date → premier retenu +
    note au rapport.
  Implémentation : migration `007_duree_max_et_peremption` (appliquée),
  `AcquisitionImportService` réécrit (`analyser()` + `import($mapping)`
  + `normaliserDateImport` + `resoudreCategorieId`), contrôleur 2 étapes
  (fichier en `sys_get_temp_dir()`, chemin en session), template 3 états.
  Tests : 549 Unit (1333 assertions), 56 Integration (225) dont 7
  `AcquisitionImportTest` réécrits. ⚠️ Fixture élargie : le cleanup
  supprime aussi les acquisitions `IMP-%` (sinon FK 005 bloque la
  suppression des catégories TEST-).

- **v0.30.0** (2026-10-18, sans ticket) : **référencement
  automatique + import/export Excel/CSV**. Décisions produit validées
  par l'utilisateur au fil de la session :
  - modèle de référence **global** (écran Administration → Référence à
    créer, pas par catégorie), variables `YY`/`YYYY`/`CAT`/`N…`, défaut
    `YYCATNNN` → `26COR001` ; ⚠️ l'écran catégorie montre le code SEUL
    (`COR`), jamais la référence complète (confusion passée) ;
  - compteur du n° d'ordre **remis à zéro chaque année**, par catégorie
    (`27COR001` en 2027) ;
  - auto-référence appliquée UNIQUEMENT à la **validation
    d'acquisition** et à l'**import** (colonne `reference` vide → auto,
    remplie → forcée) ; création/modif manuelle d'équipement = saisie
    libre conservée ;
  - import : groupement par `facture_reference` → acquisition
    **auto-validée** avec placeholder `facture à importer`
    (`AcquisitionManager::FACTURE_A_IMPORTER`), fournisseur
    find-or-create, facture déjà connue → groupe ignoré (UNIQUE, jamais
    de fusion) ; action `update_facture` autorisée sur acquisition
    validée (ne touche QUE `facture_document`) ;
  - export « données » Excel + CSV sur `/equipements` (format plat de
    consultation, non destiné au réimport).
  Implémentation : migration `006_references_auto` (appliquée),
  `ReferenceGenerator` (REGEXP MySQL + MAX+1 sur groupe capturant,
  code catégorie self-healing), `AcquisitionImportService`
  (PhpSpreadsheet `IOFactory::load` gère xlsx ET csv),
  `ReferenceController` + template avec aperçu JS, champ Code dans
  l'écran catégorie (prefill JS + validation serveur),
  `AcquisitionProcess::create_equipement_process` réécrit (références
  auto/forcées, les deux branches lot/unités). Tests : 540 Unit (1304
  assertions), 55 Integration (216) dont 6 nouveaux
  `AcquisitionImportTest` (CSV end-to-end). ⚠️ Les anciennes
  références « référence produit-N » (`ligne.reference.'-'.$i`) sont
  remplacées par l'auto — changement de comportement assumé et
  documenté au guide (§2.2).

- **v0.29.20** (2026-10-18, sans ticket) : **scan QR non connecté —
  `?redirect=` réellement émis**. Le test navigateur de bout en bout a
  montré que `deniAccessUnlessGranted('ROLE_USER')` levait
  `AccessDeniedException` AVANT le code produisant
  `/se_connecter?redirect=/qr/{id}` : le dispatcher intercepte et
  renvoie vers `/`, le paramètre était perdu (code mort en pratique,
  le fix login v0.29.19 ne recevait jamais rien). Réordonnancement :
  vérification « utilisateur connecté » avant la garde de rôle. +1 test
  Unit (anonyme avec garde en échec → login avec redirect). Test visuel
  navigateur validé : scan connecté avec contrôle ouvert → ajout +
  fiche contrôle. Effet de bord : le scan d'un équipement TEST- dans un
  contrôle réel ouvert a fait échouer toute la suite Integration (FK
  `fk_controle_ligne_equipement` sans CASCADE) →
  `DatabaseFixture::deleteAcquisition()` supprime désormais les lignes
  `controle_ligne` liées AVANT les équipements. Commit `6df04da`.
  503 tests Unit (1250 assertions), 114 Integration (288).
  Travail direct sur `main`.
- **v0.29.19** (2026-10-18, sans ticket) : **login — `?redirect=`
  honoré**. Point de contrôle du parcours « scan QR non connecté » :
  l'étape 1 (`QrRedirectController` → `/se_connecter?redirect=/qr/{id}`)
  fonctionnait, mais l'étape 2 était cassée —
  `AppUserAuthController::login()` ne lisait jamais le paramètre et
  redirigeait vers `'.'` (tableau de bord). Le formulaire sans attribut
  `action` poste sur l'URL courante, le paramètre survivait donc au
  POST : côté login c'était du code mort. Fix : après authentification,
  redirection vers `redirect` si chemin local (garde anti open redirect
  `^/(?!/)` — URL absolues et scheme-relative rejetées). 3 tests Unit
  (`AppUserAuthControllerTest`) + assertion du fallback sur le test
  existant. Commit `9a2125f`. 502 tests Unit (1249 assertions), 114
  Integration (288). Travail direct sur `main`.
- **v0.29.18** (2026-10-18, sans ticket) : **parcours « ajout
  d'équipement »** — après le clic sur « Contrôler » (liste des
  disponibles), `ControleController::addEquipement()` redirige vers la
  fiche contrôle de la ligne créée (`update-ligne/{ligne_id}`) au lieu
  de revenir sur la page du contrôle ; repli sur edit() si l'ID n'est
  pas récupéré. Vérification du scan QR : le comportement attendu
  (utilisateur → fiche équipement ; contrôleur avec contrôle ouvert →
  ajout + fiche contrôle) était déjà implémenté, mais la branche
  « contrôle » ne vérifiait pas le rôle — un ex-contrôleur rétrogradé
  ROLE_USER avec un contrôle ouvert ajoutait une ligne puis était
  refusé sur update-ligne → garde `isGranted('ROLE_CONTROLLEUR')` dans
  `QrRedirectController::redirect()`. Constat annexes : le statut
  `en_cours` (ENUM controle.statut) n'est jamais assigné dans app/ —
  `findActiveByUser` (ouvert) couvre tous les contrôles actifs.
  `DatabaseFixture::findControleLigne()` ajouté. 2 tests Unit
  (ControleControllerHandlersTest) + 1 test Unit QR + 1 test
  Integration du parcours complet (ControleControllerAddEquipementTest).
  Commits `2edbbd0` (feat) et `197b4ea` (fix qr). 499 tests Unit
  (1242 assertions), 114 Integration (288). Travail direct sur `main`.
- **#97 + #98** (v0.29.3) : **Dettes 1 et 2 soldées — toutes les
  dettes documentées sont désormais soldées.** #97 :
  `IndexController::updateSmtp()` → `updateSystemSettings()`, route
  canonique `/update-system-settings`, `/update-smtp` conservée en
  alias POST (une redirection perdrait le corps POST), Twig à jour,
  1 test Integration (route canonique). #98 :
  `EnvironmentFileParser::setAll()` — 3-4 écritures disque → 1 dans
  `updateSystemSettings()` ; `_dump()` protected pour le comptage en
  test. 3 tests Unit. 458 tests Unit (1122 assertions), 105
  Integration (248). Travail direct sur `main`.
- **#96** (v0.29.1) : `QrCodeReader` supprimé — classe entièrement
  morte (zéro appelant de `read`/`readAsBase64`/`exists`/`getFilePath`,
  factory `qrCodeReader()` jamais appelée). Elle contenait le dernier
  `header()`+`exit` du projet : **`app/` est 100 % Response** (hors
  bootstrap.php, qui précède le dispatcher). Suppression figée par
  test. 455 tests Unit (1114 assertions), 104 Integration (245).
- **#95** (v0.29.0) : **Dette 13 soldée** — les 7 actions de
  `QrRedirectController` retournent des Response/RedirectResponse/
  JsonResponse (le dispatcher faisait déjà `->send()`). Actions
  désormais testables en Unit : 12 tests (`QrRedirectControllerActionsTest`).
  `extractId()` factorisé (7 copies), factory `utilisateurManager()`
  retirée (code mort #94), `error_log()` de debug nettoyés.
  455 tests Unit (1112 assertions), 104 Integration (245).
  Travail direct sur `main`.
- **#94** (v0.28.3) : **Dette 12 soldée** — `getControleById()` /
  `getUtilisateurById()` étaient du code mort (chaînes method_exists,
  zéro appel) : supprimées. Fix documenté d'`addFlash()` : les flashs
  QR étaient perdus (method_exists toujours faux + écriture dans
  `$_SESSION['flash_bag']`, jamais lu par `session.getFlashBag.all`) →
  `getFlashBag()->add()`. 2 tests Unit. 443 tests Unit (1090
  assertions), 104 Integration (245). Travail direct sur `main`.
- **#93** (v0.28.2) : `UtilisateurManager::findAll()` projeté sur
  `PUBLIC_FIELDS` (Dette 8 étendue) — fin du SELECT * qui exposait le
  hash password aux vues Twig. Consommateurs audités (list,
  isLastSuperAdmin, contrôleurs ControleController) : tous couverts.
  Audit des autres findAll : toutes colonnes consommées, SELECT *
  justifié. 2 tests Unit (`UtilisateurManagerFindAllTest`).
  441 tests Unit (1086 assertions), 104 Integration (245).
  Travail direct sur `main`.
- **#92** (v0.28.1) : migration des 4 derniers Managers aux helpers
  d'écriture #91 — `UtilisateurManager` (alignement),
  `ClubManager` (`id=1` forcé, singleton), `AcquisitionLigneManager`
  (règles métier conservées : `equipements_generes` forcé à 0,
  traduction duplicate key), `EquipementManager` (9 coercitions `??`
  conservées, défauts null délégués à `CREATE_NULL_DEFAULTS`).
  `tableName()` ajouté à Club et AcquisitionLigne. 7 tests de
  caractérisation `save()` ajoutés (PDOException par réflexion pour
  le SQLSTATE chaîne '23000'). **Vague 15 « écritures » terminée :
  tous les _create/_patch passent par les helpers.**
  439 tests Unit (1077 assertions), 104 Integration (245).
  Travail direct sur `main`.
- **#91** (v0.28.0) : **Vague 15 ouverte** — `filteredInsert()` /
  `filteredUpdate()` génériques dans `AbstractManager` (SQL dynamique
  façon UtilisateurManager, filtrage + défauts + hook `tableName()`).
  7 Managers migrés (Acquisition, Categorie, Controle, ControleLigne,
  Fournisseur, Emplacement, EquipementControle) ; `tableName()` ajouté
  à Controle/ControleLigne. Changement documenté : clés supplémentaires
  ignorées (ex-PDOException) et `_patch` partiel possible. 4 fixtures
  #90 (Emplacement, EquipementControle) corrigées — elles utilisaient
  des clés arbitraires impossibles en production. 9 tests Unit sur les
  helpers. 432 tests Unit (1049 assertions), 104 Integration (245).
  Travail direct sur `main`.
- **#90** (v0.27.3) : caractérisation des `save()` — 11 tests sur les
  5 Managers de #88 (INSERT/UPDATE, filtrage _patch, défaut
  hash_remarques). Helper de capture dupliqué x5 (factorisable).
  423 tests Unit (1030 assertions), 104 Integration (245). Travail
  direct sur `main`.
- **#89** (v0.27.2) : uniformisation du contrat `findId()` —
  `ControleManager` / `ControleLigneManager` passent de `fetch()` brut
  (`false`) à `?: null`. Appelants audités : tous en test falsy, donc
  iso-comportement. Tests #88 mis à jour. 412 tests Unit, 104
  Integration, inchangés et verts. Travail direct sur `main`.
- **#88** (v0.27.1) : couverture Unit des Managers non testés — 17
  tests de caractérisation (5 Managers). Divergence findId
  false/null caractérisée, non corrigée. 412 tests Unit (1005
  assertions), 104 Integration (245). Travail direct sur `main`.
- **#87** (v0.27.0) : `findOneByCriteria()` générique dans
  `AbstractManager` — 7 copies factorisées. Managers simples :
  `tableName()` uniquement. EquipementManager : soft delete sur
  `baseWhereClause()`. AcquisitionManager : surcharge + hydratation
  via `parent::`. Fix documenté : validation des clés de critères
  EquipementManager (buildWhereClause #68). Piège : double WHERE
  base+critères → AND. 7 tests Unit de caractérisation. 395 tests
  Unit (965 assertions), 104 Integration (245). Travail direct sur
  `main`.
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
**🎯 Vague 14 complète** (#68 à #90 livrés).
**🎯 Vague 15 « écritures » terminée** (#91 + #92 : tous les
`_create`/`_patch` passent par `filteredInsert()`/`filteredUpdate()`).
**🎯 Dette 12 soldée** (#94 : code mort supprimé, flash QR réparé).
**🎯 Dette 13 soldée** (#95 : QrRedirectController en Response —
actions testables, 12 tests Unit).
**🎯 #96 : plus aucun `header()`/`exit` dans `app/`** (QrCodeReader,
classe morte, supprimée) — substrat propre pour les chantiers
fonctionnels.
**🎯 Dettes 1 et 2 soldées** (#97 + #98, v0.29.3) — **TOUTES les
dettes documentées du projet sont soldées** (1 à 14).
**Prochaine session** : terrain entièrement libre pour une nouvelle
vague fonctionnelle.