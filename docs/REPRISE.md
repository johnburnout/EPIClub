# EPIClub — Fichier de reprise

**Dernière mise à jour** : 2026-10-13 (fin de session #71 — Vague 14 en cours)
**Version courante** : v0.19.0
**Branche** : `main` (travail direct sur main, pas de PR)

---

## État du projet

Application de gestion EPI (équipements de protection individuelle)
pour club d'escalade. PHP 8.5, Symfony components (HttpFoundation,
Mailer, Mime), Twig, MySQL, PHPUnit 11.

### Versions récentes

| Version | Ticket | Description | Commit |
|---|---|---|---|
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

### Chantiers soldés

- 🎯 **Vague 11 — injection de dépendances** : **COMPLÈTE**.
  - Tous les contrôleurs ont une factory `protected` par manager.
  - **67 tests Unit** ajoutés sur les 3 derniers tickets
    (#63 Club, #64 Emplacement, #65 Fournisseur).
  - Pattern finalisé : `TestableXxxController` + builder, `postRequest()`
    avec `replace()` puis `set('csrf_token', ...)`, `validateCsrf()`
    no-op dans le testable.
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

- **348 tests** verts (Unit + Domain), **818 assertions**.
- **8 tests Integration** (contrôleurs, nécessitent serveur HTTP sur
  `localhost:8000`), skippés si serveur absent.
- **1 test Integration Domain** (`AcquisitionManagerFindAllIntegrationTest`,
  nécessite MySQL), skippé si base indisponible.
- Commande : `php vendor/bin/phpunit`
- Avec Integration : démarrer `php -S localhost:8000 -t public` dans un autre terminal.

---

## Prochaines cibles

### Vague 14 — en cours (refactors Engine + perf SQL)

- ✅ **#68** — `buildWhereClause()` dans `AbstractManager`.
- ✅ **#69** — `JournalManager` : suppression `getPdo()`, extraction
  `buildOrderByClause()`, fix du bug `ORDER BY` (500).
- ✅ **#70** — `JournalController` : fix `ArgumentCountError`
  (`EncryptionService` sans `ConfigProvider`). Détecté en test manuel
  navigateur, jamais en Unit.
- ✅ **#71** — `AcquisitionManager::findAll()` : 2N+1 → 3 requêtes.
  `findIds()` batch sur FournisseurManager et UtilisateurManager.
  10 tests Unit + 1 test Integration de caractérisation.
- Candidats suivants (par ordre de pertinence) :
  - **#72** (prochain) — `AcquisitionLigneManager` : N+1 sur
    `CategorieManager::findId()` dans `findByAcquisition()`, et
    `delete()` qui instancie `new AcquisitionManager()` à chaque
    appel (couplage + risque de casse des tests contrôleur qui
    mockent les managers — signature potentiellement modifiée).
    Le pattern `findIds()` est maintenant en place : ajouter
    `CategorieManager::findIds()` sera trivial.
  - **Tests Unit sur les contrôleurs non encore couverts**
    (`IndexController`, `QrRedirectController`, `AppUpdateController`,
    partiellement `AppUserRegisterController`) — priorité remontée
    après #70 : un bug 500 est resté invisible 7 versions faute de
    tests.
  - Factoriser `encryptionService()` dans `AbstractController`
    (Dette 5 — duplication `ControleController` / `JournalController`).
  - Fix `limit <= 0` dans `JournalManager` (`LIMIT -5` → 500).
  - Fix `'u.nom, u.prenom ASC'` (ambiguïté multi-colonnes).
  - Audit des requêtes SQL concaténées restantes
    (`EquipementManager::findOneByCriteria()`).
  - `AbstractManager::findOneByCriteria()` générique ? (attention à
    `EquipementManager` — cas particulier `deleted_at IS NULL` — et à
    l'hydratation spécifique d'`AcquisitionManager`).
  - Nouveaux tests Unit sur les `Manager` non couverts (ClubManager,
    EmplacementManager, FournisseurManager, CategorieManager, etc.).
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
- Les tests #68, #69, #70 et #71 (`AbstractManagerBuildWhereClauseTest`,
  `JournalManagerTest`, `JournalControllerTest`,
  `AcquisitionManagerFindAllTest`, `FournisseurManagerFindIdsTest`,
  `UtilisateurManagerFindIdsTest`) utilisent déjà l'attribut
  `#[DataProvider]` ou n'en ont pas besoin — pas d'ajout de dette.
- **Ticket à ouvrir** : oui, un petit ticket « chore: migrer
  @dataProvider vers #[DataProvider] ».

### Dette 5 — `encryptionService()` dupliqué dans les contrôleurs

- `ControleController::encryptionService()` (#50b) et
  `JournalController::encryptionService()` (#70) sont identiques :
  `new EncryptionService($this->configProvider())`.
- **Recommandation** : extraire une factory `protected
  encryptionService()` dans `AbstractController`.
- **Ticket à ouvrir** : oui, dans la continuité de la Vague 14.
- Connexe : `EquipementManager::encryptionService()` (Dette 6) utilise
  `new ConfigProvider(__DIR__ . '/../../.env.local.php')` en dur —
  divergence à aligner sur `$this->configProvider()`.

### Dette 6 — `EquipementManager::encryptionService()` instancié à chaque appel

- Factory `protected encryptionService()` crée une nouvelle instance
  à chaque `getHistoriqueControles()`.
- Acceptable vu l'usage peu fréquent.
- Si le manager se met à déchiffrer en masse, prévoir un cache.
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

### Dette 9 — Absence de contraintes FOREIGN KEY sur `acquisition`

- `SHOW CREATE TABLE acquisition` ne déclare aucune `CONSTRAINT
  ... FOREIGN KEY`. Or `fournisseur_id` et `saisie_par` sont
  `int NOT NULL` et référencent logiquement `fournisseur.id` et
  `utilisateur.id`.
- Conséquence : rien n'empêche un `fournisseur_id = 999999` orphelin.
  D'où le `?? null` défensif dans `AcquisitionManager::findAll()` —
  **réellement atteignable**, pas du code mort.
- **Impact** : intégrité référentielle non garantie au niveau SGBD ;
  les FK orphelines doivent être gérées applicativement (ce que fait
  déjà le `?? null`).
- **Recommandation** : ajouter les contraintes FK + `ON DELETE
  RESTRICT` (ou `SET NULL` avec colonne nullable, à trancher), mais
  cela nécessite de nettoyer les orphelins existants avant.
- **Ticket à ouvrir** : oui, mais **après** la Vague 14 (chantier
  schéma, à planifier avec migration de données).

### Dette 10 — `AcquisitionManager::findAll()` non testable unitairement sur le nombre de requêtes

- `findAll()` fait `new FournisseurManager()` et `new UtilisateurManager()` :
  instances réelles, non injectables. Le mock PDO ne capte que la
  première requête (SELECT acquisition).
- Conséquence : la revendication « 3 requêtes au lieu de 2N+1 »
  n'est pas vérifiable en Unit. Elle ne l'est qu'en Integration
  (observation de la base).
- **Recommandation** : extraire des factories `protected
  fournisseurManager()` et `protected utilisateurManager()` (pattern
  Vague 11), surchargeables en test. Aligner sur
  `TestableJournalController` (Vague 14).
- **Ticket à ouvrir** : oui, dans la continuité de la Vague 14.
- Connexe : Dette 5 (`encryptionService()` dupliqué dans les
  contrôleurs).

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
  (vert avant, vert après) — cf. #71.
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

---

## Commandes utiles

Tests (depuis la racine du projet) :

- `php vendor/bin/phpunit` — suite complète.
- `php vendor/bin/phpunit tests/Unit/Engine/FooTest.php` — un fichier.
- `php vendor/bin/phpunit --filter testMethodName` — une méthode.
- `php vendor/bin/phpunit --display-deprecations` — affiche le détail
  des dépréciations PHPUnit.

Serveur HTTP pour tests Integration :

- `php -S localhost:8000 -t public` (dans un second terminal).

Base de données de test :

- `mysql -u root epiclub -e "SELECT id, username, role FROM utilisateur WHERE username='admin';"`
- `mysql -u root epiclub -e "SHOW CREATE TABLE acquisition\G"` — utile
  pour vérifier NOT NULL et contraintes FK.

Pattern release Git :

- `git add <fichiers>`
- `git commit -m "type(#ticket): description" -m "Closes #XX"`
- `git push origin main`
- `git tag -a vX.Y.Z -m "vX.Y.Z — #ticket description"`
- `git push origin vX.Y.Z`

Pattern non-régression #71 (refactor iso-comportement) :

- Sauvegarder le fichier refactoré : `cp app/Domain/Xxx.php /tmp/Xxx.refactored.php`
- Restaurer la version HEAD~1 : `git checkout HEAD~1 -- app/Domain/Xxx.php`
- Lancer le test Integration : doit être **VERT** (caractérisation)
- Restaurer le refactor : `cp /tmp/Xxx.refactored.php app/Domain/Xxx.php`
- Relancer : doit être **VERT** (non-régression)

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
    (Vague 14, `TestableJournalController`) : teste le constructeur
    réel. À privilégier pour les contrôleurs qui n'ont pas encore
    été migrés Vague 11 — c'est ce qui a permis de capturer le bug
    #70.
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
  (`createMock(\PDO::class)` fonctionne). Attention à trois pièges :
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
    FournisseurManager()` dans `AcquisitionManager::findAll()` n'est
    pas mockable. Le mock PDO ne capte que la première requête.
    Conséquence : impossible de compter les requêtes en Unit (cf.
    Dette 10). Pattern à surveiller pour #72.

---

## Contexte de la session précédente

- **#71** (v0.19.0) : `AcquisitionManager::findAll()` — suppression du
  2N+1. `findIds()` batch sur `FournisseurManager` et
  `UtilisateurManager`. 10 tests Unit, 1 test Integration de
  caractérisation. Périmètre strictement limité à `findAll()` :
  `findId()` et `findOneByCriteria()` restent inchangés (3 requêtes
  fixes, pas un N+1). Premier ticket Vague 14 « perf SQL ».
- **#70** (v0.18.10) : `JournalController` — fix `ArgumentCountError`
  (`new EncryptionService()` sans argument, ligne 23). Détecté en test
  manuel navigateur, jamais en Unit (aucun test n'existait sur ce
  contrôleur). Migré au pattern Vague 11 : factories
  `journalManager()` et `encryptionService()`. Propriétés passées en
  `protected`. +2 tests Unit avec `TestableJournalController` qui
  appelle le vrai `parent::__construct()` (pattern Vague 14). Test
  rouge d'abord (pile d'appel capturée).
- **#69** (v0.18.9) : `JournalManager` — suppression de `getPdo()`
  (code mort), extraction de `buildOrderByClause()` (whitelist pure,
  testable) et **fix du bug** `ORDER BY c.date_debut DESC ASC` (500)
  sur `order_by` inconnu + `order_dir`. +23 tests Unit. Premier
  ticket appliquant l'exception « fix de bug documenté » à la règle
  d'iso-comportement.
- **#68** (v0.18.8) : `AbstractManager::buildWhereClause()` extrait
  dans `AbstractManager`, 6 Managers migrés (Acquisition, Categorie,
  Emplacement, EquipementControle, Fournisseur, Utilisateur).
  `EquipementManager` non touché (cas particulier `deleted_at IS NULL`).
  +14 tests Unit sur `buildWhereClause` (test rouge d'abord, attribut
  `#[DataProvider]`). **Premier ticket Vague 14**.

**🎯 Vague 11 complète** (3 contrôleurs, 67 tests Unit).
**🎯 Vague 14 en cours** (#68, #69, #70 et #71 livrés).
**Prochaine session** : **#72** (`AcquisitionLigneManager` : N+1 sur
`CategorieManager::findId()`, `delete()` qui instancie `new
AcquisitionManager()`). Le pattern `findIds()` est maintenant en place,
`CategorieManager::findIds()` sera trivial à ajouter.