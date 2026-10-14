# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s'inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)
et le projet adhère au [versionnage sémantique](https://semver.org/lang/fr/).

Les versions antérieures à 0.17.9 ne sont pas documentées ici ; se référer
à l'historique Git (`git log`, `git tag`).

## [Unreleased]

## [v0.24.1] — 2026-10-14

### Changed

- Factorisation de la factory `encryptionService()` dans
`AbstractController`. Auparavant dupliquée à l'identique dans
`ControleController` (#50b) et `JournalController` (#70), elle est
désormais héritée par les 17 contrôleurs de l'application. Dette 5
soldée. Aucun changement de comportement observable.

## [v0.24.0] — 2026-10-14

### Removed

- Routes mortes `/mon_compte` et `/creer_compte` supprimées
(`AppUserRegisterController::account()` et `::edit()`), ainsi que
leur déclaration dans `ressources/routes.php`. Ces routes rendaient
des templates inexistants (`user_account.twig`, `user_register.twig`)
→ 500 si tapées directement. Aucune référence dans l'UI, les
templates, ou les emails. Doublons fonctionnels de
`UtilisateurController` (qui utilise les vrais templates
`utilisateur_show.twig` / `utilisateur_form.twig`). Dette 14 soldée.

## [0.23.0] — 2026-10-14

### Added (tests)

- **`tests/Unit/Controller/AppUserRegisterControllerTest.php`** :
5 tests Unit sur les méthodes GET d'`AppUserRegisterController`
qui n'étaient pas couvertes par `HandlersTest` (qui couvre les POST) :
- `account()` : garde `ROLE_USER` (accès refusé + flash) ;
- `edit()` : garde `ROLE_ADMIN` (accès refusé + flash) ;
- `forgotPassword()` GET : rendu du formulaire (200) ;
- `forgotPasswordConfirm()` : rendu de la page de confirmation (200) ;
- `resetPassword()` GET avec token valide : rendu du formulaire (200)
(token manquant/invalide/expiré déjà couverts par `HandlersTest`).

### Notes

- ⚠️ **Périmètre limité** : les rendus de `account()` et `edit()`
**ne sont pas testés** car les templates `user_account.twig` et
`user_register.twig` n'existent pas (cf. Dette 14). Seules les
gardes d'accès sont testées.
- **Dette 14 documentée** : les routes `/mon_compte` et
`/creer_compte` sont déclarées dans `ressources/routes.php` mais
rendent des templates manquants (renommés en `utilisateur_show.twig`
et `utilisateur_form.twig`). Bug **dormant** : aucune référence
dans l'UI ni les emails. Ticket #77 à ouvrir (corriger ou supprimer).

## [0.22.1] — 2026-10-13

### Fixed

- **#75 — `QrRedirectController::choicePage()`** : chemin du template
`choice_page.twig` corrigé en `qr/choice_page.twig`.
Le template existe dans `templates/qr/` mais le `render()` le
cherchait à la racine de `templates/`, produisant une
`Twig\Error\LoaderError: Unable to find template "choice_page.twig"`.
Symptôme : 500 à l'utilisateur sur la route `/qr/choix/{id}`.
Détecté en test manuel navigateur après #73.

### Added (tests)

- **`tests/Integration/Controller/QrRedirectControllerChoicePageTest.php`** :
test Integration `GET /qr/choix/{id}` → 200 + présence de la
référence et du libellé de l'équipement dans la page.
- **`DatabaseFixture::createEquipement()`** : helper pour créer un
équipement de test (acquisition + catégorie + référence uniques).
- **`DatabaseFixture::cleanupTestData()`** étendu : nettoyage des
équipements avec `reference LIKE 'TEST-%'` (filet de sécurité).

### Notes

- **Cause racine** : le template a probablement été déplacé dans
`templates/qr/` lors d'un rangement, mais le `render()` n'a pas
suivi. `choicePage()` retourne une `Response` normale (pas de
`header()` + `exit`), donc elle aurait pu être testée en Unit —
mais elle dépend de Twig et de la BDD, ce qui la place côté
Integration.
- **Dette 13 inchangée** : les autres méthodes publiques de
`QrRedirectController` (`redirect`, `generateQr`, `downloadQr`,
`viewQr`, `saveQr`, `apiGenerateQr`) utilisent toujours
`header()` + `exit`.

## [0.22.0] — 2026-10-13

### Changed

- **#74 — `IndexController` : rattrapage Vague 11.**
Deux dépendances instanciées en dur (`EnvironmentFileParser`,
`MailerService`) sont désormais fournies par des factories
`protected` mockables en test Unit (pattern Vague 11, aligné sur
`QrRedirectController` #73, `JournalController` #70).

Aucun changement observable : les routes, templates, flux HTTP et
la logique métier sont inchangés.

### Added (tests)

- **`tests/Unit/Controller/IndexControllerTest.php`** : 5 tests Unit
couvrant :
- `index()` : redirection vers `/se_connecter` si non authentifié,
vers `/tableau_de_bord` sinon ;
- `dashboard()` : rendu du template `tableau_de_bord.twig`
(statut 200 + contenu non vide — **pas d'assertion sur le HTML**,
cf. Points de vigilance) ;
- `updateSmtp()` : rejet si champs SMTP obligatoires vides (flash
error + redirect), rejet si `ROOT_URL` invalide (flash error +
redirect) — **branches non couvertes par les 7 tests Integration
`IndexControllerRootUrlTest`**.
- **`tests/Unit/Controller/Support/TestableIndexController.php`** :
sous-classe de test (pattern Vague 14) qui
- appelle le **vrai** `parent::__construct()` ;
- surcharge `updateLastActivity()` en no-op ;
- surcharge `validateCsrf()` en no-op (CSRF testé isolément dans
`CsrfValidatorTest`) ;
- injecte un mock `EnvironmentFileParser` et/ou
`MailerServiceInterface` via surcharge des factories.

### Notes

- ⚠️ **Périmètre limité** :
- `systemSettings()` n'est pas testé en Unit : couvert en
Integration (`IndexControllerRootUrlTest`, 7 tests) ;
- `testMail()` n'est pas testé en Unit : effet de bord réseau
(SMTP), à tester manuellement ou en Integration.
- `MailerServiceInterface` (Vague 8) permet de mocker `MailerService`
en Unit si on veut étendre la couverture à `testMail()` plus tard.

## [0.21.0] — 2026-10-13

### Changed

- **#73 — `QrRedirectController` : rattrapage Vague 11.**
Les 6 dépendances (`EquipementManager`, `ControleManager`,
`ControleLigneManager`, `UtilisateurManager`, `QrCodeGenerator`,
`QrCodeReader`) étaient instanciées en dur dans le constructeur
(6 `new`), ouvrant 4 connexions PDO à chaque instanciation.
Elles sont désormais fournies par des factories `protected`
mockables en test Unit (pattern Vague 11, aligné sur
`EquipementController`, `ControleController`, `AcquisitionController`).

Le constructeur ne fait plus que `parent::__construct($session)`.
Aucun changement observable : les routes, templates, flux HTTP et
logique métier sont inchangés.

### Added (tests)

- **`tests/Unit/Controller/QrRedirectControllerTest.php`** : 4 tests
Unit couvrant :
- `testConstructDoesNotOpenPdo` : instanciation sans `new XxxManager()`,
aucun PDO ouvert ;
- `testEquipementManagerFactoryReturnsInjectedMock` : la factory
`equipementManager()` retourne bien le mock injecté (via réflexion) ;
- `testGetCurrentUserReturnsNullWhenNoUserInSession` ;
- `testGetCurrentUserReturnsUserFromSession`.
- **`tests/Unit/Controller/Support/TestableQrRedirectController.php`** :
sous-classe de test (pattern Vague 14) qui
- appelle le **vrai** `parent::__construct()` ;
- surcharge `updateLastActivity()` en no-op (évite
`new UtilisateurManager()` → PDO) ;
- injecte 4 mocks via surcharge des factories.

### Notes

- ⚠️ **Périmètre limité** : les actions publiques (`redirect()`,
`choicePage()`, `generateQr()`, `downloadQr()`, `viewQr()`,
`saveQr()`, `apiGenerateQr()`) ne sont **pas** testées en Unit
car elles utilisent `header()` + `exit`, ce qui tue le processus
PHP. Un refactor architectural (retour de `Response` au lieu de
`header()` + `exit`) serait nécessaire. Documenté en **Dette 13**
de `docs/REPRISE.md`.
- `QrCodeGenerator` et `QrCodeReader` ne sont pas mockables (pas
d'interface). Ils sont laissés en réel dans le testable, comme
`FactureUploader` dans `TestableAcquisitionController`.

## [0.20.0] — 2026-10-13

### Changed

- **#72 — `AcquisitionLigneManager::findByAcquisition()` : N+1 → 2 requêtes.**
Remplace la boucle `CategorieManager::findId()` par un batch
`findIds()` (`IN (...)`). Pour 100 lignes d'acquisition :
**101 requêtes → 2 requêtes fixes** (ou 1 seule si le résultat
est vide).

Périmètre strict : `findId()` et `findByReference()` restent
inchangés (2 requêtes fixes, pas un N+1).

- **#72 — `AcquisitionLigneManager::delete()`** : suppression du
`new AcquisitionManager()` en dur, remplacé par une factory
`protected acquisitionManager()` (pattern Vague 11). Aucun
changement observable : la logique de refus sur acquisition
validée est inchangée.

Objectif : rendre `AcquisitionManager` mockable dans les tests
Unit de `delete()`.

Iso-comportement strict préservé :
- `isset()` conservé sur `categorie_id` → clé absente si `null` ;
- `?? null` sur le map → FK orpheline = clé présente + valeur
`null` ;
- ordre des lignes inchangé.

### Added

- `CategorieManager::findIds(array $ids): array` — récupération
batch, résultat indexé par `id`, déduplication, `$ids` vide = pas
de requête. Symétrique de `FournisseurManager::findIds()` (#71).

### Tests

- **Unit** : `CategorieManagerFindIdsTest` (3 tests),
`AcquisitionLigneManagerFindByAcquisitionTest` (4 tests),
`AcquisitionLigneManagerDeleteTest` (1 test).
- **Integration** : `AcquisitionLigneManagerFindByAcquisitionIntegrationTest`
(1 test de caractérisation — catégorie existante, catégorie
orpheline).
- Séquence de non-régression : test Integration **vert** sur
HEAD~1 (v0.19.0, ancien `findByAcquisition()`) puis **vert** sur
HEAD (refactor).

### Documentation

- `docs/REPRISE.md` : Dette 9 étendue à `acquisition_ligne`
(absence de FK sur `categorie_id` et `acquisition_id`).
- `docs/REPRISE.md` : Dette 10 étendue à `AcquisitionLigneManager`
(`findByAcquisition()` non testable unitairement sur le nombre de
requêtes).

## [0.19.0] - 2026-10-13

### Changed

- **#71 — `AcquisitionManager::findAll()` : 2N+1 → 3 requêtes.**
Remplace la boucle `findId()` par un batch `findIds()`
(`IN (...)`) sur `FournisseurManager` et `UtilisateurManager`.
Pour 100 acquisitions avec fournisseur + rédacteur :
**201 requêtes → 3 requêtes fixes** (ou 1 seule si le résultat
acquisition est vide).

Périmètre strict : seule `findAll()` est touchée. `findId()` et
`findOneByCriteria()` restent inchangés (3 requêtes fixes, pas un
N+1).

Iso-comportement strict préservé :
- `isset()` conservé sur `fournisseur_id` / `saisie_par` → clé
absente si `null` (comportement historique) ;
- `?? null` sur le map → FK orpheline = clé présente + valeur
`null` (comportement historique de `findId()` qui retournait
`null`) ;
- ordre des acquisitions inchangé.

### Added

- `FournisseurManager::findIds(array $ids): array` — récupération
batch, résultat indexé par `id`, déduplication, `$ids` vide = pas
de requête.
- `UtilisateurManager::findIds(array $ids): array` — idem.

### Tests

- **Unit** : `AcquisitionManagerFindAllTest` (4 tests — early return,
forme du résultat, FK null défensive, FK orpheline),
`FournisseurManagerFindIdsTest` (3 tests),
`UtilisateurManagerFindIdsTest` (3 tests).
- **Integration** : `AcquisitionManagerFindAllIntegrationTest`
(1 test de caractérisation — F+R, FK orpheline ; le cas
`fournisseur_id = NULL` n'est pas testable car la colonne est
`NOT NULL` en base).
- Séquence de non-régression : test Integration **vert** sur
HEAD~1 (v0.18.10, ancien `findAll()`) puis **vert** sur HEAD
(refactor) — preuve d'iso-comportement.

### Documentation

- `REPRISE.md` : Dette 8 (hash de mot de passe exposé dans
`redacteur`), Dette 9 (absence de contraintes FK sur
`acquisition`), Dette 10 (`findAll()` non testable unitairement
sur le nombre de requêtes — instanciation en dur de
`FournisseurManager` / `UtilisateurManager`).

## [Unreleased]

## [0.18.10] — 2026-10-13

### Fixed

- **#70** : `JournalController` levait une `ArgumentCountError` à chaque
instanciation (`new EncryptionService()` sans argument, ligne 23) —
le constructeur d'`EncryptionService` exige un `ConfigProvider` depuis
#50b (v0.18.3). Symptôme : erreur 500 à l'utilisateur sur toute route
du journal (`/journaux`, `/journaux/{id}`, `/journaux/{id}/pdf`).
Détecté en test manuel dans le navigateur, jamais en test automatisé
car `JournalController` n'avait aucun test Unit.
- **Cause racine** : `JournalController` n'avait pas été migré au
pattern #50b/Vague 11. Son constructeur instanciait `JournalManager`
et `EncryptionService` en dur, sans factory mockable — invisible
pour la suite de tests existante.

### Changed

- **#70** : `JournalController` migre au pattern Vague 11 :
- factory `protected journalManager(): JournalManager` ;
- factory `protected encryptionService(): EncryptionService`
(reprend le pattern de `ControleController::encryptionService()`
#50b : `new EncryptionService($this->configProvider())`).
- **#70** : `JournalController::$journalManager` et
`JournalController::$encryptionService` passent de `private` à
`protected` pour être injectables dans `TestableJournalController`.
- Aucun autre changement observable : routes, templates, flux HTTP
et logique métier inchangés.

### Added (tests)

- **`tests/Unit/Controller/JournalControllerTest.php`** : 2 tests Unit
couvrant :
- `testConstructDoesNotThrow` : instanciation du contrôleur via
`TestableJournalController` sans `ArgumentCountError`. **Test rouge
avant fix** (pile d'appel `JournalController.php:23` →
`ArgumentCountError`).
- `testEncryptionServiceFactoryInstantiatesWithConfigProvider` :
la factory `encryptionService()` (appelée via réflexion sur la
classe parente) retourne bien une instance `EncryptionService`.
- **`tests/Unit/Controller/Support/TestableJournalController.php`** :
sous-classe de test qui
- appelle le **vrai** `parent::__construct()` (teste le constructeur
réel, contrairement à certaines sous-classes de la Vague 11 qui
court-circuitaient) ;
- surcharge `updateLastActivity()` en no-op (évite
`new UtilisateurManager()` → PDO) ;
- injecte des mocks `JournalManager` / `EncryptionService` via
surcharge des factories.

### Tests

- Unit : 336 → 338 tests (+2), 794 assertions (inchangées).
- Integration : 7 tests (inchangés, skippés si serveur absent).
- **Total : 338 tests verts, 0 warning, 0 dépréciation.**

### Notes

- **Leçon** : le bug est passé sous le radar parce que
`JournalController` n'avait **aucun test Unit**. Toutes les routes
du journal étaient à 500 depuis v0.18.3 (#50b), soit 7 versions.
Signal fort en faveur de la cible « Tests Unit sur les contrôleurs
non encore couverts » de la Vague 14 (les contrôleurs restants :
`IndexController`, `QrRedirectController`, `AppUpdateController`,
partiellement `AppUserRegisterController`).
- **Dette connexe** : le même bug de factorisation
`encryptionService()` peut se reproduire si `AbstractController`
n'expose pas de factory commune. `ControleController` et
`JournalController` dupliquent maintenant la même factory.
Factorisation à envisager dans un ticket séparé (cf. Dette 5).
- **Dépréciation PHP 8.5** : `ReflectionMethod::setAccessible(true)`
est no-op depuis PHP 8.1 et dépréciée depuis PHP 8.5. Le test
`testEncryptionServiceFactoryInstantiatesWithConfigProvider`
l'utilisait initialement — supprimé (cf. Points de vigilance de
`docs/REPRISE.md`).

## [0.18.9] — 2026-10-13

### Changed

- #69 : `JournalManager` supprime sa méthode privée `getPdo()` (15
lignes) et utilise directement `$this->db` hérité d'`AbstractManager`.
La méthode faisait `property_exists($this, 'pdo')` puis
`property_exists($this, 'db')` puis `method_exists($this,
'getConnection')` — or seule la 2ᵉ branche pouvait matcher, `$pdo` et
`getConnection()` n'ayant jamais existé. Code mort probablement copié
d'un projet où `JournalManager` n'héritait pas d'`AbstractManager`.
- #69 : `JournalManager` extrait une méthode `protected
buildOrderByClause(?string $orderBy, ?string $orderDir): string`
(pure, testable) qui remplace le `switch` + ternaire dans
`getControlesClotures()`. Whitelist explicite via
`const ORDER_BY_WHITELIST` (3 colonnes : `controleur`, `date_debut`,
`date_fin`), aligné sur le pattern `AbstractManager` (#68).
- #69 : direction par défaut `DESC` quand `order_dir` est absent ou
non whitelisté. La casse est normalisée (comparaison en lowercase,
sortie en uppercase).
- Aucun autre changement observable : routes, templates, flux HTTP
et logique métier inchangés.

### Fixed

- #69 : `JournalManager::getControlesClotures()` produisait un SQL
invalide (`ORDER BY c.date_debut DESC ASC`) quand `order_by` était
inconnu **et** `order_dir` non vide. Le `switch` tombait sur le
`default` (`c.date_debut DESC`) puis concaténait ` ASC` ou ` DESC`,
donnant une clause à deux directions. MySQL renvoyait une erreur 500.
Le bug était latent (aucun appelant ne le déclenchait — filtres
whitelistés côté Twig) mais atteignable via manipulation directe de
l'URL ou tout futur appelant.
- #69 : comportement de `order_by='controleur'` avec `order_dir`
absent : avant `u.nom, u.prenom` (MySQL appliquait `ASC` implicitement,
tri par nom croissant — incohérent avec l'intention d'un journal), après
`u.nom, u.prenom DESC` (les plus récents d'abord, cohérent avec le
défaut global `c.date_debut DESC`).

### Added (tests)

- **`tests/Unit/Domain/JournalManagerTest.php`** : 23 tests Unit
couvrant :
- `getPdo()` supprimé (vérification par `method_exists()`) ;
- 20 cas sur `buildOrderByClause()` via `#[DataProvider]` :
nominal, whitelist contrôlé, casse de direction, direction
invalide, colonne inconnue, 3 injections SQL (`'x; DROP TABLE'`,
`"x' OR '1'='1"`) ;
- 3 smoke tests sur `getControlesClotures()` avec PDO mocké :
retour du struct `['data', 'total', 'page', 'limit',
'totalPages']`, clause `ORDER BY` produite via whitelist, fix du
bug sur `order_by` inconnu + `order_dir='asc'`.
- **`tests/Unit/Domain/Support/TestableJournalManager.php`** :
sous-classe de test qui court-circuite `AbstractManager::__construct()`
(pas de PDO réel), expose `buildOrderByClause()` en public et injecte
un PDO mocké dans `$this->db`. **Piège** : la propriété du PDO mocké
est nommée `$mockPdo`, pas `$pdo`, pour ne pas faire matcher
`property_exists($this, 'pdo')` dans `getPdo()` tant que la méthode
n'est pas supprimée.

### Tests

- Unit : 313 → 336 tests (+23), 760 → 792 assertions (+32).
- Integration : 7 tests (inchangés, skippés si serveur absent).
- **Total : 336 tests verts.**

### Notes

- Amendement de la règle EPIClub « iso-comportement strict » : la
règle autorise désormais un fix de bug documenté dans le même commit
que le refactor, à condition qu'il soit isolé, testé (test rouge
d'abord) et documenté dans la section `### Fixed` du CHANGELOG.
C'est le premier ticket qui applique cette exception.
- Restent hors périmètre (tickets séparés si les cas se manifestent
en pratique) : le fix de `'u.nom, u.prenom ASC'` (le `ASC` ne
s'applique qu'à `u.prenom`, ambiguïté multi-colonnes) et le fix de
`limit <= 0` (`LIMIT -5` provoque une erreur MySQL).

## [0.18.8] — 2026-10-13

### Changed

- #68 : `AbstractManager` extrait une méthode `protected
buildWhereClause(array $criteria): array` qui centralise la
construction des clauses WHERE (Vague 14 — refactors Engine).
- #68 : 6 Managers migrés pour utiliser la nouvelle méthode :
`AcquisitionManager`, `CategorieManager`, `EmplacementManager`,
`EquipementControleManager`, `FournisseurManager`,
`UtilisateurManager`. La boucle `foreach` qui construisait
`WHERE col=:col AND col2=:col2` était **dupliquée à l'identique**
dans les 6 `findOneByCriteria()`.
- #68 : objectif — une seule source de vérité pour la clause WHERE,
et validation stricte des noms de colonne (`^[a-zA-Z_][a-zA-Z0-9_]*$`,
même regex que `buildOrderClause()`).
- #68 : `EquipementManager::findOneByCriteria()` **non touché**
(cas particulier : clause `deleted_at IS NULL` préfixée en dur,
qui ne rentre pas dans le pattern standard).
- Aucun changement de comportement observable hors refactor :
routes, templates, flux HTTP et logique métier inchangés.

### Security

- #68 : les noms de colonne passés à `findOneByCriteria()` sont
désormais validés par regex. Un `InvalidArgumentException` est
levé sur clé invalide, au lieu de concaténer la clé telle quelle
dans le SQL. Ferme une porte d'injection SQL théorique si un
appelant passait une clé non contrôlée (`'id OR 1=1'`,
`'id; DROP TABLE'`, …).
- Iso-comportement en pratique : les 6 Managers migrés ne
recevaient déjà que des littéraux propres de leurs appelants
(`'email'`, `'username'`, `'facture_reference'`, `'nom'`,
`'reset_token'`, `'libelle'`, …).

### Added (tests)

- **`tests/Unit/Engine/AbstractManagerBuildWhereClauseTest.php`** :
14 tests Unit sur `buildWhereClause()` couvrant :
- tableau vide → clause vide + params vides,
- 1 critère → ` WHERE col=:col`,
- N critères → ` WHERE a=:a AND b=:b`,
- ordre d'itération préservé,
- colonnes valides `reset_token`, `col_2` (underscore + digits),
- valeur passée telle quelle dans les params (apostrophe
`o'brien@x.y` préservée),
- 8 cas de colonnes invalides via `#[DataProvider]` :
`'id OR 1=1'`, `'id-col'`, `'u.id'`, `'id; DROP TABLE'`,
`'1col'`, `''`, `"id'"`, `'id)'` → `InvalidArgumentException`.
- **`TestableAbstractManager`** : sous-classe concrète de test
qui surcharge le constructeur en no-op (pas de PDO) et expose
`buildWhereClause()` en `public` via `exposeBuildWhereClause()`.

### Tests

- Unit : 299 → 313 tests (+14), 741 → 760 assertions (+19).
- Integration : 7 tests (inchangés, skippés si serveur absent).
- **Total : 313 tests verts.**

### Notes

- Ouverture de la Vague 14 (refactors Engine). Premier ticket
livré : #68. Candidats suivants : audit des requêtes SQL
concaténées restantes, tests Unit sur les Managers non couverts,
cache d'instance `EncryptionService`, migration
`@dataProvider` → `#[DataProvider]` (Dette 4).
- Le nouveau test utilise déjà l'attribut `#[DataProvider]`
(pas l'annotation `@dataProvider`) — la Dette 4 porte sur les
tests **existants** qui n'ont pas encore migré.

## [0.18.7] — 2026-10-12

### Changed

- #65 : `FournisseurController` extrait une factory `protected
fournisseurManager()` (Vague 11 — injection de dépendances) et
remplace les 4 `new FournisseurManager()` en dur (`list`, `show`,
`edit`, `delete`).
- #65 : objectif — rendre le manager **mockable** en test Unit et
supprimer l'ouverture PDO déclenchée par `AbstractManager::__construct()`.
- Aucun changement de comportement observable hors refactor :
routes, templates, flux HTTP et logique métier inchangés.
- 🎯 **Vague 11 complète** : les 3 derniers contrôleurs
(ClubController #63, EmplacementController #64, FournisseurController
#65) sont maintenant équipés de factories `protected`. Tous les
contrôleurs de l'app sont mockables en test Unit.

### Added (tests)

- **`TestableFournisseurController`** : sous-classe de test qui injecte
un `FournisseurManager` mocké, surcharge la factory
`fournisseurManager()`, `updateLastActivity()` en no-op, et
`validateCsrf()` en no-op (CSRF testé isolément dans `CsrfValidatorTest`).
- **`TestableFournisseurControllerBuilder`** : builder de test avec
mocks silencieux par défaut.
- **`FournisseurControllerTest`** : 20 tests Unit couvrant :
- `edit()` : GET nominal, GET avec id valide, GET avec id inconnu
→ redirect, POST `nom` vide / whitespace-only → pas de save,
troncature `nom` à 64 chars, POST valide → save + redirect.
- `edit()` : validation email souple via `#[DataProvider]` (6 cas) —
vide accepté, valide accepté, invalides rejetés.
- `edit()` : stockage `email` / `phone` à `null` si vides.
- `delete()` : GET rejeté, POST avec acquisitions → refus, POST sans
acquisitions → delete + redirect, POST id inconnu → redirect.
- `list()` / `show()` : smoke tests via factory mockée.

### Tests

- Unit : 279 → 299 tests (+20), 685 → 741 assertions (+56).
- Integration : 7 tests (inchangés, skippés si serveur absent).
- **Total : 299 tests verts.**

### Notes

- Fin de la Vague 11. Bilan : 3 contrôleurs (Club, Emplacement,
Fournisseur), **67 tests Unit ajoutés** sur cette vague
(#63 + #64 + #65).
- Pattern Vague 11 finalisé et documenté : factory `protected` sur le
contrôleur, `TestableXxxController` + builder, `postRequest()` avec
`replace()` puis `set('csrf_token', ...)`, `validateCsrf()` no-op
dans le testable.

## [0.18.6] — 2026-10-12

### Changed

- #64 : `EmplacementController` extrait une factory `protected
emplacementManager()` (Vague 11 — injection de dépendances) et
remplace les 4 `new EmplacementManager()` en dur (`list`, `show`,
`edit`, `delete`).
- #64 : objectif — rendre le manager **mockable** en test Unit et
supprimer l'ouverture PDO déclenchée par `AbstractManager::__construct()`.
- Aucun changement de comportement observable hors refactor :
routes, templates, flux HTTP et logique métier inchangés.

### Added (tests)

- **`TestableEmplacementController`** : sous-classe de test qui injecte
un `EmplacementManager` mocké, surcharge la factory
`emplacementManager()`, `updateLastActivity()` en no-op, et
`validateCsrf()` en no-op (CSRF testé isolément dans `CsrfValidatorTest`).
- **`TestableEmplacementControllerBuilder`** : builder de test avec
mocks silencieux par défaut.
- **`EmplacementControllerTest`** : 27 tests Unit couvrant :
- `edit()` : GET nominal, GET avec id valide, GET avec id inconnu
→ redirect, POST `libelle` vide / whitespace-only → pas de save,
troncature `libelle` à 64 chars, POST valide → save + redirect.
- `edit()` : validation URL `image` via `#[DataProvider]` (12 cas) —
`http`/`https` acceptés, `javascript:`/`data:`/`file:`/`ftp:`/
`mailto:`/sans schéma rejetés.
- `edit()` : stockage `image` (URL fournie) / `null` (vide).
- `delete()` : GET rejeté, POST avec équipements → refus,
POST sans équipements → delete + redirect, POST id inconnu → redirect.
- `list()` / `show()` : smoke tests via factory mockée.

### Tests

- Unit : 252 → 279 tests (+27), 615 → 685 assertions (+70).
- Integration : 7 tests (inchangés, skippés si serveur absent).
- **Total : 279 tests verts.**

## [0.18.5] — 2026-10-12

### Changed

- #63 : `ClubController` extrait une factory `protected clubManager()`
(Vague 11 — injection de dépendances) et remplace le `new ClubManager()`
en dur dans `show()`.
- #63 : objectif — rendre le manager **mockable** en test Unit et
supprimer l'ouverture PDO déclenchée par `AbstractManager::__construct()`.
- Aucun changement de comportement observable hors refactor :
routes, templates, flux HTTP et logique métier inchangés.

### Added (tests)

- **`TestableClubController`** : sous-classe de test qui injecte un
`ClubManager` mocké, surcharge la factory `clubManager()` et
`updateLastActivity()` en no-op (évite `new UtilisateurManager()`).
- **`TestableClubControllerBuilder`** : builder de test avec mocks
silencieux par défaut. Utilise `MockBuilder` directement car
`createMock()` est `protected` en PHPUnit 11.
- **`ClubControllerTest`** : 20 tests Unit sur `show()` :
- GET nominal : `findParameters()` appelé, `save()` jamais.
- `nom` vide / whitespace-only → pas de `save()`.
- `email` : 6 cas via `#[DataProvider]` (vide accepté, valide,
`+`, sans `@`, sans domaine, sans user).
- `activite` : 9 cas via `#[DataProvider]` — les 5 valeurs de
`self::ACTIVITES` acceptées, hors whitelist rejetées
(dont `injection SQL-like`, `casse différente`).
- POST valide : `save()` appelé avec les bonnes valeurs,
`RedirectResponse` vers `/admin/club`.
- Troncature défensive du `nom` à 64 chars (`varchar(64)`).

### Notes

- **Piège CSRF en test POST** : `private const CSRF_TOKEN` doit être
déclarée dans la classe de test. Sans elle, `self::CSRF_TOKEN` vaut
`null` silencieusement et `ParameterBag::replace()` filtre la clé,
d'où `submitted=NULL` côté `CsrfValidator`. Pattern retenu :
`$request->request->replace($params)` **puis**
`$request->request->set('csrf_token', self::CSRF_TOKEN)`.
- Les 3 fichiers de test sont dans `tests/Unit/Controller/` et
`tests/Unit/Controller/Support/`.

### Tests

- Unit : 232 → 252 tests (+20), 565 → 623 assertions (+58).
- Integration : 7 tests (inchangés, skippés si serveur absent).
- **Total : 252 tests verts.**

## [0.18.4] — 2026-10-11

### Changed
- #50c : `MailerService` reçoit désormais un `ConfigProvider` en constructeur (Vague 11) au lieu de lire `$_ENV['MAILER_DSN']`.
- #50c : les 2 appelants injectent leur `ConfigProvider` :
  - `AppUserRegisterController::mailerService()` → `$this->configProvider()`.
  - `IndexController::testMail()` → `new ConfigProvider($envFile)`.
- #50c : `Transport::fromDsn()` (DSN malformé) est normalisé en `MailDeliveryException` par `getMailer()` — l'appelant n'a qu'une seule exception à gérer.

### Notes
- Lecture du DSN **lazy** conservée : le transport est instancié au premier `sendEmail()`, pas au constructeur. Iso-comportement avec l'implémentation pré-#50c.
- La politique stricte reste inchangée : DSN absent ou vide → `MailDeliveryException`.
- Fin du chantier « ConfigProvider » (#50) : les 3 volets (#50a, #50b, #50c) sont livrés. `$_ENV` n'est plus utilisé directement dans les services (`EncryptionService`, `MailerService`).

### Tests
- Unit : 228 → 232 tests (+4), 561 → 565 assertions (+4).
- Integration : 7 tests (inchangés).
- **Total : 232 tests verts** (4 nouveaux sur `MailerService`).

## [0.18.3] — 2026-10-11

### Changed
- #50b : `EncryptionService` reçoit désormais un `ConfigProvider` en constructeur (Vague 11) et expose `isEncrypted()`.
- #50b : chiffrement/déchiffrement centralisés dans `EncryptionService` — `ControleController` (helpers `encryptRemarque`/`decryptRemarque`/`isEncrypted`) et `EquipementManager::getHistoriqueControles()` délèguent au service via une factory `protected encryptionService()`.
- #50b : politique stricte `SECRET_KEY` absente/invalide → `RuntimeException` au constructeur (aligné #52).

### Notes
- La partie « `IndexController::updateSmtp()` → `ConfigProvider::set()` » du ticket #50b a été absorbée par #59 (v0.18.2) : `updateSmtp()` utilise déjà `EnvironmentFileParser` et n'écrit plus manuellement. Aucun refactor supplémentaire.
- Le ciphertext reste au format `base64(IV || ciphertext)`, inchangé. Compatibilité totale avec les données existantes.
- `EncryptionService::decrypt()` retourne maintenant `null` pour les valeurs non déchiffrables (au lieu de retourner l'input) — aucun appelant avant #50b, donc iso-comportement observable préservé.

### Tests
- Unit : 216 → 228 tests (+12), 543 → 561 assertions (+18).
- Integration : 7 tests (inchangés).
- **Total : 228 tests verts** (12 nouveaux sur `EncryptionService`).

## [0.18.2] - 2026-10-11

### Added
- #59 : `ROOT_URL` est désormais éditable depuis l'écran d'administration *Configuration système*.
- #59 : nouveau validateur `Epiclub\Engine\RootUrlValidator` — URL absolue http/https, sans query ni fragment, slash final normalisé.
- #59 : tests Unit `RootUrlValidatorTest` (14 cas) et tests Integration `IndexControllerRootUrlTest` (7 cas).

### Changed
- #59 : `IndexController::updateSmtp()` migre l'écriture de `.env.local.php` vers `Epiclub\Engine\EnvironmentFileParser` (bénéficie de l'échappement `var_export()` de #49).
- #59 : `IndexController::systemSettings()` lit `ROOT_URL` via `ConfigProvider` (Vague 12).
- #59 : libellé de la card « Configuration système » (au lieu de « Serveur SMTP ») et du `<h3>` (au lieu de « Paramètres système – Configuration SMTP »).

### Notes
- Utilisateur `admin` de la base de test locale passé en `ROLE_SUPER_ADMIN` (setup des tests Integration, pas un changement projet).
- Dette identifiée : `updateSmtp()` gère désormais plus que SMTP. Renommage cosmétique (`updateSystemSettings()`, route `/update-system-settings`) à envisager dans un ticket séparé.
- Dette identifiée : `EnvironmentFileParser::set()` réécrit le fichier à chaque appel. Un `setAll()` éviterait 3-4 écritures par requête. Ticket séparé.

## [0.18.1] — 2026-10-09

### Fixed

- **ControleController** : la remarque générale d'un contrôle **ouvert**
s'affiche désormais en clair dans le formulaire d'édition
(régression #52, introduite en v0.17.27).
- Cause : depuis #52, `edit()` POST chiffre `hash_remarques` dès
l'écriture. Mais la vue `edit()` GET ne déchiffrait qu'à la condition
`$readonly && statut === 'cloture'`. Résultat : le textarea
`remarques_generales` affichait le ciphertext base64 pour tout
contrôle non clôturé.
- Fix : déchiffrement **inconditionnel** dans `edit()` GET.
`decryptRemarque()` retourne `null` pour les valeurs non chiffrées
(legacy, base64 invalide), auquel cas la valeur d'origine est
conservée — iso-comportement strict pour les données pré-#52.

- **Base de données** : `controle.hash_remarques` passe de
`VARCHAR(64)` à `TEXT` (régression #52, introduite en v0.17.27).
- Cause : le chiffrement `base64(IV || ciphertext)` gonfle la taille
d'environ 2× par rapport au plaintext. `VARCHAR(64)` ne laissait que
~16 octets de plaintext utile, soit ~15 caractères — toute remarque
plus longue déclenchait un
`SQLSTATE[22001] Data too long for column 'hash_remarques'`.
- Le bug était **latent avant #52** (chiffrement à la clôture
seulement), mais **bloquant dès #52** (chiffrement à l'écriture).
- Fix : migration `004_alter_controle_hash_remarques_text.sql`
(`ALTER TABLE controle MODIFY COLUMN hash_remarques TEXT NULL`),
alignée sur `controle_ligne.remarque` (#53).
- ⚠️ **Action requise** : exécuter la migration SQL sur les
installations existantes.

### Notes

- Le déchiffrement inconditionnel dans `edit()` GET n'a **aucun impact
sur la sécurité** : le contrôleur ou son propriétaire sont les seuls
à accéder à cette vue (garde `deniAccessUnlessGranted` + `canEdit`).
Un admin en `isAdminEdit` peut voir/modifier le contrôle d'un autre
utilisateur — comportement voulu, inchangé.
- ⚠️ **Dette technique identifiée** : `edit()` GET instancie
`new CategorieManager()` et `new EmplacementManager()` en dur
(non mockables en test Unit). La suite Vague 11 n'est pas complète
sur `ControleController`. À traiter dans une issue dédiée.

### Tests

- Unit : 201 tests (inchangés), 524 assertions (inchangées).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 289 tests verts.**

### Added (tests)

- (aucun — le fix est comportemental, validé manuellement en prod
test et via les 5 tests #52 de non-régression)

## [0.18.0] — 2026-10-09

### BREAKING CHANGE

- **AbstractController** : `getBaseUrl()` ne fallback plus sur
`$_SERVER['SERVER_NAME']` (issue #50a-bis).
- `ROOT_URL` doit désormais être présent dans `.env.local.php`. En cas
d'absence, vide ou non-string, `getBaseUrl()` lance une
`RuntimeException` avec un message d'aide explicite.
- ⚠️ **Action requise avant déploiement** : vérifier que
`.env.local.php` contient la clé `ROOT_URL`. Les installations
antérieures à #50a (v0.17.24) n'ont probablement pas cette clé. Sans
`ROOT_URL`, toute génération de lien absolu (email de reset password,
QR codes d'équipement) échouera avec une 500.
- Ajouter au minimum :
`ROOT_URL="https://votre-domaine.fr"` (sans slash final).
- Le fallback transitoire `SERVER_NAME` introduit en v0.17.24 est
supprimé, comme planifié dans le CHANGELOG de cette version.

### Security

- `getBaseUrl()` échoue désormais explicitement si `ROOT_URL` est
absent, vide ou non-string, au lieu de produire silencieusement une
URL potentiellement incorrecte.
- Cas d'erreur silencieuse supprimés :
- **reverse-proxy / load-balancer** : `SERVER_NAME` peut ne pas
correspondre à l'URL publique vue par le client.
- **config serveur mal renseignée** : `SERVER_NAME` par défaut
`localhost` produisait des liens non cliquables.
- **installation partielle** : l'admin ne recevait aucune alerte
et l'app continuait avec une URL inutilisable.
- Le strict **fail loud** rend ces problèmes immédiatement visibles.

### Changed

- **AbstractController::getBaseUrl()** : suppression du `error_log`
de fallback, du calcul `HTTPS`/`SERVER_NAME`, et du default
`localhost`. La méthode fait désormais 4 lignes, une seule source de
vérité (`ConfigProvider::get('ROOT_URL')`).
- Aucun changement de comportement observable **lorsque `ROOT_URL` est
présent** : la valeur retournée est identique (URL rtrimée).

### Tests

- Unit : 199 → 201 tests (+2), 521 → 524 assertions (+3).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 289 tests verts** (201 Unit + 88 Integration).

### Added (tests)

- **`testGetBaseUrlThrowsWhenRootUrlMissing`** : `ROOT_URL` absent
→ `RuntimeException`.
- **`testGetBaseUrlThrowsWhenRootUrlEmpty`** : `ROOT_URL = ''`
→ `RuntimeException`.
- **`testGetBaseUrlThrowsWhenRootUrlIsNotString`** : `ROOT_URL = 42`
→ `RuntimeException`.
- **`testGetBaseUrlThrowsWithExplicitMessage`** : le message
contient `ROOT_URL` et `.env.local.php` (aide au diagnostic).
- **`testGetBaseUrlDoesNotFallbackToServerNameNorHttps`** : garde-fou,
`SERVER_NAME` et `HTTPS` définis ne changent rien, on throw.

### Changed (tests)

- **`testGetBaseUrlDoesNotUseHttpHostHeader`** adapté : injecte un
`ROOT_URL` sûr + un `HTTP_HOST` hostile, vérifie que `ROOT_URL`
gagne et que `evil` n'apparaît pas dans le résultat.

### Removed (tests)

- **`testGetBaseUrlFallsBackToServerNameWhenRootUrlMissing`** :
fallback supprimé.
- **`testGetBaseUrlFallsBackToServerNameWhenRootUrlEmpty`** : idem.
- **`testGetBaseUrlFallsBackToLocalhostWhenServerNameMissing`** :
idem.

## [0.17.27] — 2026-10-09

### Security

- **ControleController** : chiffrement de `controle.hash_remarques` **dès
l'écriture** dans `edit()` (issue #52).
- La remarque générale du contrôle était stockée en clair dans
`controle.hash_remarques` jusqu'à la clôture. Elle n'était chiffrée
qu'à l'appel de `cloturer()`.
- Fenêtre d'exposition : entre `edit()` (POST) et `cloturer()`, la
remarque était lisible par quiconque accède à la BDD (dump, backup,
accès admin direct, log SQL).
- Fix : `edit()` chiffre via les nouveaux helpers `encryptRemarque()` /
`cryptoConfig()`, alimentés par `ConfigProvider` (Vague 11).
`edit()` en lecture (contrôle clôturé) déchiffre via
`decryptRemarque()`.

- **ControleController** : `cloturer()` **idempotent** sur
`hash_remarques` (issue #52).
- Avant : `cloturer()` re-chiffrait systématiquement
`hash_remarques`, ce qui aurait produit un **double chiffrement**
silencieux pour les contrôles ouverts au moment du déploiement du fix.
- Fix : `isEncrypted()` détecte les valeurs déjà chiffrées (via un
`openssl_decrypt` test) et les conserve telles quelles. Les valeurs
legacy en clair (contrôles créés avant le déploiement) sont chiffrées
à la clôture, préservant la compatibilité.
- Les remarques de lignes (`controle_ligne.remarque`) suivent la même
logique : chiffrées à la clôture si en clair, conservées si déjà
chiffrées.

- Politique stricte : `SECRET_KEY` absente ou invalide dans
`.env.local.php` → `RuntimeException`. On ne stocke jamais en clair,
on ne perd jamais silencieusement une remarque (mieux vaut une 500
visible qu'une fuite discrète).

### Changed

- **ControleController** : suppression de l'`include` en dur de
`.env.local.php` dans `edit()` (lecture) et `cloturer()`. Les 3 blocs
openssl dupliqués sont remplacés par les helpers `encryptRemarque()`,
`decryptRemarque()`, `isEncrypted()`, alimentés par
`ConfigProvider::get('SECRET_KEY')` et `get('CIPHER_METHOD')`.
- Prépare l'extraction d'un `EncryptionService` centralisé (#50b) :
la logique crypto est maintenant isolée dans 4 méthodes (dont
`cryptoConfig()` privée), prêtes à être déplacées dans un service.

- Aucun changement de comportement observable hors fix #52 : routes,
templates, flux HTTP et logique métier inchangés. La valeur affichée
à l'utilisateur est identique (déchiffrée à la lecture, chiffrée à
l'écriture).

### Added

- **`ControleController::encryptRemarque(?string): ?string`** :
chiffre une remarque libre en `base64(IV || ciphertext)`. Retourne
`null` si la valeur est vide. `protected` pour être testable.

- **`ControleController::decryptRemarque(?string): ?string`** :
déchiffre une remarque. Retourne `null` si la valeur n'est pas un
ciphertext valide (legacy en clair, base64 corrompu, mauvaise clé).
`protected` pour être testable.

- **`ControleController::isEncrypted(?string): bool`** : détecte si
une valeur est déjà chiffrée. Utilisé par `cloturer()` pour rester
idempotent.

- **`ControleController::cryptoConfig(): array`** : charge et valide
`SECRET_KEY` + `CIPHER_METHOD` depuis `ConfigProvider`.
`RuntimeException` si la clé est absente ou non-hex.

- **`tests/Fixtures/env.test.php`** : fixture de configuration
réutilisable (clé AES-256 de test, cipher, ROOT_URL). Prépare
#50b/#50c.

### Tests

- Unit : 194 → 199 tests (+5), 506 → 521 assertions (+15).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 287 tests verts** (199 Unit + 88 Integration).

### Added (tests)

- **`ControleControllerEncryptionTest`** : 5 tests Unit couvrant :
- `testEditPostEncryptsHashRemarques` : `edit()` stocke un ciphertext
base64 déchiffrable (vérification round-trip avec la clé de fixture).
- `testEditPostStoresNullWhenRemarquesEmpty` : remarque vide → `null`,
pas de ciphertext.
- `testEditPostThrowsWhenSecretKeyMissing` : `SECRET_KEY` absente →
`RuntimeException`, `save()` non appelé.
- `testCloturerDoesNotDoubleEncryptAlreadyEncrypted` : une valeur déjà
chiffrée est conservée telle quelle (pas de double chiffrement).
- `testCloturerEncryptsLegacyPlaintextValue` : une valeur legacy en
clair est chiffrée à la clôture (compatibilité).

- **`TestableControleController`** : ajout du 6e paramètre
`?ConfigProvider` + surcharge `configProvider()`. Surcharge
`updateLastActivity()` en no-op pour éviter l'ouverture PDO en test
Unit (aucun test n'exerçait ce comportement, iso-comportement
observable).

- **`TestableControleControllerBuilder`** : ajout de
`withConfigProvider()` + `ConfigProvider` par défaut pointant sur la
fixture `env.test.php`.

## [0.17.26] — 2026-10-08

### Security

- **UtilisateurController** + **AppUserRegisterController** : longueur
minimale du mot de passe portée de **6 à 12 caractères** (issue #53,
recommandation ANSSI 2024).
- `AppUserRegisterController::resetPassword()` validait `strlen($password) < 6`.
- `UtilisateurController::edit()` (création admin) et `show()` (édition)
n'avaient **aucune** validation de longueur : un admin pouvait créer
un utilisateur avec un mot de passe d'1 caractère.
- Fix : constante `UtilisateurManager::MIN_PASSWORD_LENGTH = 12` +
méthode statique `UtilisateurManager::validatePassword()` utilisée
par les 3 points d'entrée. Une seule source de vérité.

- **4 contrôleurs** : troncature défensive des champs à longueur fixe
(issue #53).
- Les champs `varchar(32)`, `varchar(64)` et `TEXT` étaient écrits
sans troncature côté PHP. En mode MySQL non-strict, la valeur était
silencieusement coupée ; en mode strict, une erreur SQL était levée.
- Fix : `mb_substr()` (UTF-8 safe) sur les 5 champs restants :
`fournisseur.nom` (64), `emplacement.libelle` (64),
`club.nom` (64), `utilisateur.nom/prenom/username` (32),
`controle_ligne.remarque` (2000, TEXT).

### Added

- **`UtilisateurManager::MIN_PASSWORD_LENGTH`** : constante publique
(12).
- **`UtilisateurManager::validatePassword(string): ?string`** : méthode
statique retournant un message d'erreur ou `null` si valide.
- `strlen()` et non `mb_strlen()` : minimum en octets, conservateur
pour les caractères multi-octets (`é` = 2 octets).

### Changed

- **`UtilisateurController`** : extraction d'une factory `protected`
`utilisateurManager()` (Vague 11) + 5 remplacements
(`list`, `edit`, `show`, `delete`, `isLastSuperAdmin`).
- Objectif : rendre le manager mockable en test Unit.

- **`UtilisateurController::edit()`** et **`show()`** : troncature
`mb_substr(trim(...), 0, 32)` sur `nom`, `prenom`, `username`.
L'email (varchar 255) n'est pas tronqué mais reste validé par
`filter_var(FILTER_VALIDATE_EMAIL)`.

### Tests

- Unit : 181 → 194 tests (+13), 483 → 506 assertions (+23).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 282 tests verts** (194 Unit + 88 Integration).

### Added (tests)

- **`UtilisateurManagerTest`** : 9 tests (constante + dataprovider
7 cas + message d'erreur).
- **`UtilisateurControllerHandlersTest`** : 3 tests (rejet MDP court,
acceptation MDP valide, troncature des 3 champs).
- **`ControleControllerHandlersTest`** : +1 test
(`testUpdateLigneTruncatesLongRemarque`).
- **`TestableUtilisateurController`** + builder (Vague 11).

## [0.17.25] — 2026-10-08

### Changed

- **AppUserRegisterController** : extraction du SQL reset password
vers `UtilisateurManager` (issue #51).
- Le contrôleur tapait directement dans PDO via
`$utilisateurManager->getDb()` dans deux méthodes
(`forgotPassword()` et `resetPassword()`), violant la séparation
des couches.
- Fix : `UtilisateurManager::setResetToken()` et
`UtilisateurManager::clearResetToken()` encapsulent ces requêtes.
Le contrôleur n'appelle plus que ces méthodes.

- **AppUserRegisterController** : extraction de 3 factories `protected`
pour la testabilité Unit (Vague 11) :
- `utilisateurManager()`
- `clubManager()`
- `mailerService()`
- Objectif : rendre ces dépendances mockables en test Unit et
supprimer les connexions PDO déclenchées par
`AbstractManager::__construct()`.

- **`resetPassword()`** : utilise désormais `clearResetToken()` au lieu
d'un `save()` partiel qui mettait à jour `password` + les 3 colonnes
reset en une seule requête. Le résultat observable est identique
(2 UPDATE au lieu d'1), mais la séparation des responsabilités est
plus nette (le manager gère le token, le contrôleur gère le flux).

### Added

- **`Epiclub\Engine\MailerServiceInterface`** (Vague 8) : interface
extraite pour rendre `MailerService` mockable en test Unit.
- `MailerService` implémente l'interface (la classe reste `final`).
- `AppUserRegisterController::mailerService()` retourne l'interface
au lieu de la classe concrète.
- ⚠️ Ce changement prépare la suppression de la dépendance à
`$_ENV['MAILER_DSN']` dans `MailerService` (traité dans #50c).

### Tests

- Unit : 168 → 181 tests (+13), 446 → 483 assertions (+37).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 269 tests verts** (181 Unit + 88 Integration).

### Added (tests)

- **`AppUserRegisterControllerHandlersTest`** : 13 tests Unit couvrant :
- `forgotPassword()` : rejet email invalide, anti-énumération
(email inconnu → même réponse), anti-spam (délai < 5 min),
appel `setResetToken()` avec les bons arguments, envoi
d'email conditionnel, absorption silencieuse d'un échec SMTP.
- `resetPassword()` : token manquant/invalide/expiré, validation
mot de passe (longueur < 6, mismatch), succès (save +
clearResetToken).
- **`TestableAppUserRegisterController`** + builder (Vague 11).

## [0.17.24] — 2026-10-08

### Security

- **AbstractController** : suppression du fallback `$_SERVER['HTTP_HOST']`
dans `getBaseUrl()` (issue #50a).
- `$_SERVER['HTTP_HOST']` provient du header HTTP `Host:`, contrôlable
par le client. Un attaquant pouvait donc influencer les URL absolues
générées (QR codes, liens de reset password) et rediriger vers un
domaine malveillant (Host header injection).
- Fix : `getBaseUrl()` lit désormais `ROOT_URL` via `ConfigProvider`.
En l'absence de `ROOT_URL`, fallback sur `$_SERVER['SERVER_NAME']`
(issu de la config serveur, non spoofable) + `error_log` explicite.
- ⚠️ **Fallback transitoire** : `SERVER_NAME` reste un repli temporaire.
Le passage en strict (`RuntimeException` si `ROOT_URL` absent) est
planifié dans #50a-bis (cible v0.18.0).

### Changed

- **AbstractController** : extraction de `getBaseUrl()` (Vague 11).
- Le code était **dupliqué à l'identique** dans 3 contrôleurs
(`AppUserRegisterController`, `EquipementController`,
`QrRedirectController`).
- Fix : méthode unique dans `AbstractController`, alimentée par
`ConfigProvider::get('ROOT_URL')`.
- Les 3 contrôleurs suppriment leur copie locale et appellent
`$this->getBaseUrl()`.

- **AbstractController** : factory `protected configProvider()`
(Vague 11) pour injecter un `ConfigProvider` en test Unit.

### Added

- **`Epiclub\Engine\ConfigProvider`** : fournisseur centralisé de
configuration (issue #50a).
- Lecture unique au constructeur (cache en mémoire).
- `get($key, $default)`, `has($key)`, `set($key, $value)` (mémoire
uniquement), `all()`, `persist()` (écriture via
`EnvironmentFileParser`), `getFilePath()`.
- Strict : `RuntimeException` si le fichier est absent ou ne retourne
pas un tableau.

### Tests

- Unit : 148 → 168 tests (+20), 418 → 446 assertions (+28).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 256 tests verts** (168 Unit + 88 Integration).

### Added (tests)

- **`ConfigProviderTest`** : 12 tests Unit (constructeur, get, has,
set mémoire, all, cache, filePath).
- **`AbstractControllerGetBaseUrlTest`** : 8 tests Unit dont
`testGetBaseUrlDoesNotUseHttpHostHeader` qui vérifie explicitement
que `HTTP_HOST` n'est plus utilisé.
- **`TestableAbstractController`** : sous-classe de test qui expose
`getBaseUrl()` en public et permet d'injecter un `ConfigProvider`
pré-configuré.

### Notes

- Le strict (`throw` si `ROOT_URL` absent) est reporté à #50a-bis.
- `ROOT_URL` n'est **pas encore** écrit par l'installateur : il doit
être ajouté manuellement dans `.env.local.php` pour éviter le
fallback `SERVER_NAME`.

## [0.17.23] — 2026-10-08

### Security

- **EnvironmentFileParser** : échappement des valeurs dans `_dump()`
(issue #49).
- `_dump()` écrivait les valeurs brutes dans le fichier
`.env.local.php` (un `return [...]` PHP) :

fputs($file, "    '$key' => '$value',\n");

- Conséquence 1 : une valeur contenant une apostrophe
(ex. `pass'word`) produisait un fichier PHP **syntaxiquement
invalide**, faisant planter l'application au prochain `require`
de `.env.local.php`.
- Conséquence 2 : une valeur contrôlée par un utilisateur
(ex. `MAILER_FROM` saisi dans `IndexController::updateSmtp`)
pouvait injecter des **clés arbitraires** dans le tableau
retourné (ex. `foo', 'APP_ENV' => 'dev` insère une clé
`APP_ENV` non désirée).
- Fix : `var_export((string) $key, true)` +
`var_export((string) $value, true)`. Le résultat est du PHP
valide quel que soit le contenu (`'`, `\`, `$`, `"`, `\n`,
balises `<?php`).

### Changed

- **EnvironmentFileParser** : injection du chemin de fichier au
constructeur (`?string $filePath = null`) pour rendre la classe
testable sans écrire dans le vrai `.env.local.php`.
- Défaut inchangé (`__DIR__ . '/../../.env.local.php'`), donc
iso-comportement strict pour tous les appelants existants.
- Aucun autre changement observable : la classe reste utilisée
par un seul appelant dans `app/`.

### Tests

- Unit : 139 → 148 tests (+9), 402 → 418 assertions (+16).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 236 tests verts** (148 Unit + 88 Integration).

### Added (tests)

- **`EnvironmentFileParserTest`** : 9 tests Unit couvrant :
- `testDumpEscapesSingleQuote` : `pass'word` → PHP valide.
- `testDumpEscapesBackslash` : `C:\Windows\...` → PHP valide.
- `testDumpEscapesDollar` : `Hello $user` → PHP valide.
- `testDumpEscapesDoubleQuote` : `Say "hi"` → PHP valide.
- `testDumpMakesPhpTagInert` : `<?php system(...); ?>` reste
dans une string (pas exécuté au `require`).
- `testDumpMakesDoubleQuoteInjectionInert` :
`foo', 'INJECTED' => 'bar` ne crée pas de clé `INJECTED`.
- `testDumpPreservesNormalValues` : iso-comportement sur
valeurs saines.
- `testDumpUppercasesKeys` : `lower_key` → `LOWER_KEY`.
- `testDumpPreservesExistingEntries` : les entrées déjà
présentes dans le fichier ne sont pas perdues.


## [0.17.22] — 2026-10-07

### Fixed

- **CategorieController** : validation des champs dans `edit()`
(issue #48). Suppression du `/** @todo Need validation here */`.
- `libelle` était accepté vide et persisté tel quel.
Fix : `trim()` + rejet si vide (flash `form_errors['libelle']`).
- `libelle` pouvait dépasser 32 caractères (colonne `varchar(32)`).
Fix : `mb_substr($libelle, 0, 32)` — `mb_substr()` et non `substr()`
pour ne pas couper un caractère UTF-8 en deux.
- `description` n'était pas bornée (colonne `TEXT`, 65535 octets).
Fix : troncature à `self::DESCRIPTION_MAX_LENGTH` (2000).

- **CategorieController** : nettoyage du nom de fichier image
(issue #48).
- `pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME)`
seul pouvait produire un nom vide (`../../.jpg` → `''`) ou
conserver des caractères non désirables (`<`, `>`, unicode, …).
Fix : `sanitizeUploadedFilename()` (`protected`, testable) qui
applique `basename()` + regex `[^a-zA-Z0-9_-]` + `mb_substr(100)`
+ fallback `'image'` si le nom est vidé.

- **`categorie_form.twig`** : affichage des erreurs de validation
(issue #48).
- Le template n'affichait **aucune** `form_errors`, rendant les
rejets silencieux côté utilisateur.
Fix : alerte générale en tête de formulaire + `invalid-feedback`
sous les champs `libelle` et `image`.

### Added

- **Tests Unit** sur `CategorieController::edit()` :
- `testEditRejectsEmptyLibelle`, `testEditRejectsWhitespaceOnlyLibelle` :
rejet + pas de `save()`.
- `testEditTruncatesLongLibelle`, `testEditTruncatesLongDescription` :
troncature effective.
- `testEditAcceptsValidLibelle` : cas nominal.
- `testSanitizeUploadedFilename` (13 datasets) : traversal
(`../../evil.jpg`, `/etc/passwd/secret.jpg`), dotfiles
(`.htaccess`), ponctuation seule (`...`), caractères spéciaux,
unicode, accents, longueur extrême.

### Changed

- **CategorieController** : extraction d'une factory `protected`
pour le manager interne (Vague 11 du chantier « injection de
dépendances », issue #48) :
- `categorieManager()`
- Objectif : rendre cette dépendance **mockable** en test unitaire
et supprimer les connexions PDO déclenchées par
`AbstractManager::__construct()`.

- Extraction de `sanitizeUploadedFilename()` en méthode `protected`
(pure, string → string) pour la rendre testable sans monter un
`UploadedFile` réel ni écrire sur le disque.

- Aucun changement de comportement observable hors fix #48 :
routes, managers et logique métier inchangés.

### Tests

- Unit : 121 → 139 tests (+18), 371 → 402 assertions (+31).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 227 tests verts** (139 Unit + 88 Integration).

### Added (tests)

- **`TestableCategorieController`** : sous-classe de test qui
injecte un `CategorieManager` mocké, surcharge la factory
`categorieManager()` et expose `sanitizeUploadedFilename()` en
`public` pour la tester en isolation.
- **`TestableCategorieControllerBuilder`** : builder de test qui
masque la complexité du constructeur. Utilise `MockBuilder`
directement car `createMock()` est `protected` en PHPUnit 11.

## [0.17.21] — 2026-10-07

### Security

- **EquipementController** : whitelist stricte du statut dans `edit()`
(issue #47).
- Le statut soumis via POST était assigné tel quel à
`$equipement['statut_id']` puis passé à `EquipementManager::save()`.
Or `_patch()` filtre les colonnes inconnues : `statut_id` n'existe
pas dans `$allowedFields`, donc **la colonne `statut` n'était jamais
mise à jour**. Le formulaire affichait un `<select>` fonctionnel,
mais le choix de l'utilisateur était silencieusement perdu.
- Un POST forgé (`statut='hacked'`, `''`, `'3'`) tombait
silencieusement sur `0` (Disponible) via le cast PHP `(int)`,
ou était tronqué côté BDD — sans trace.
- Fix : validation stricte via `ctype_digit()` puis
`EquipementStatut::tryFrom()`. Si la valeur est invalide, on log
et on redirige avec un flash d'erreur, sans appeler `save()`.
- Renommage du champ POST : `statut_id` → `statut`, aligné sur la
colonne BDD `club_equipement.statut`.

### Fixed

- **EquipementController** : suppression du champ `etat_usure_id`
du formulaire d'édition (issue #47).
- Colonne fantôme : aucune trace dans `club_equipement` ni dans
`EquipementManager::_patch()`. Le champ était affiché et sa valeur
ignorée. Retiré du template `equipement_form.twig` et du
`render()` de `edit()`.
- L'enum `EquipementEtats` est conservée en l'état (dette
documentée, non utilisée après ce fix).

### Added

- **`Epiclub\Enum\EquipementStatut`** : enum `int` pour les
3 statuts métier de `club_equipement.statut`
(`TINYINT`, cf. `migrations/001_initial.sql`) :
- `DISPONIBLE = 0`
- `EN_MAINTENANCE = 1`
- `HORS_SERVICE = 2`
- Expose `label()` (libellé humain) et `forSelect()`
(compatible Twig `for name, value in …`).
- ⚠️ Ne pas confondre avec `EquipementStatuts` (pluriel, `string`,
`'Libre'/'Assigné'/'Réservé'/'En controle'`) : cette dernière est
une enum **legacy** dont les valeurs ne correspondent pas à la
colonne BDD. Conservée en l'état, plus utilisée par le code après
ce fix.

### Changed

- **EquipementController** : extraction de 4 factories `protected`
pour les managers internes (Vague 11 du chantier
« injection de dépendances », issue #47) :
- `equipementManager()`
- `categorieManager()`
- `emplacementManager()`
- `acquisitionManager()`
- Tous les `new XxxManager()` en dur dans les méthodes publiques
(`list`, `getFilteredEquipments`, `show`, `edit`, `delete`,
`pdf`, `renderPdfListHtml`) sont remplacés par des appels à ces
factories.
- Objectif : rendre ces dépendances **mockables** en test unitaire
et supprimer les connexions PDO déclenchées par
`AbstractManager::__construct()`.

- Aucun changement de comportement observable hors fix #47 :
routes, templates, flux HTTP et logique métier inchangés.

### Tests

- Unit : 115 → 121 tests (+6), 350 → 371 assertions (+21).
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 209 tests verts** (121 Unit + 88 Integration).

### Added (tests)

- **`TestableEquipementController`** : sous-classe de test qui
injecte 4 managers mockés et surcharge les factories
correspondantes.
- **`TestableEquipementControllerBuilder`** : builder de test
qui masque la complexité du constructeur et fournit des mocks
silencieux par défaut. Utilise `MockBuilder` directement car
`createMock()` est `protected` en PHPUnit 11.

- **6 nouveaux tests Unit** sur `EquipementController::edit()` :
- Rejet `'hacked'` (non numérique) → pas de `save()` + redirect.
- Rejet `''` (vide) → pas de `save()` + redirect.
- Rejet `'3'` (hors enum) → pas de `save()` + redirect.
- Acceptation `'0'`, `'1'`, `'2'` via dataProvider
(3 tests) → `save()` appelé avec `statut === int` attendu.s

## [0.17.20] — 2026-10-07

### Fixed

- **ControleController** : whitelist stricte du statut dans
`updateLigne()` (issue #35).
- Le statut soumis via POST était assigné tel quel à
`$ligne['statut']` puis passé à `ControleLigneManager::save()`.
- Un POST forgé avec une valeur arbitraire (`'hacked'`) était
silencieusement tronqué par MySQL (ENUM) en chaîne vide,
produisant un état incohérent jamais matché par le `switch`
de `cloturer()`.
- Fix : validation via `ControleLigneStatut::tryFrom()`. Si la
valeur est invalide, on log et on redirige avec un flash
d'erreur, sans appeler `save()`.

- **ControleController** : `switch` exhaustif dans `cloturer()`
(issue #35).
- Le `switch` sur `$ligne['statut']` avait un `default: break`
silencieux. Une valeur BDD hors enum (héritage, corruption,
futur ajout non géré ici) laissait l'équipement avec
`controle_en_cours=0` mais `statut` inchangé — sans aucune
trace.
- Fix : `match()` exhaustif sur `ControleLigneStatut`. Les 4
cases sont couvertes explicitement. Une valeur hors enum est
loggée et traitée en `continue` (libération de l'équipement
sans toucher au statut).

### Added

- **`Epiclub\Enum\ControleLigneStatut`** : enum PHP 8.1 pour les
4 statuts de `controle_ligne`
(cf. `migrations/001_initial.sql`) :
- `a_controler`
- `controle_ok`
- `controle_ko`
- `hors_service`
- Expose `label()` (libellé humain) et `isTerminal()`.

### Changed

- **ControleController** : extraction de 4 factories `protected`
pour les managers internes (Vague 11 du chantier
« injection de dépendances », issue #35) :
- `controleManager()`
- `controleLigneManager()`
- `equipementManager()`
- `utilisateurManager()`
- Tous les `new XxxManager()` en dur dans les méthodes publiques
(`list`, `create`, `edit`, `addEquipement`, `updateLigne`,
`cloturer`, `delete`, `isUserOnline`) sont remplacés par des
appels à ces factories.
- Objectif : rendre ces dépendances **mockables** en test
unitaire et supprimer les connexions PDO déclenchées par
`AbstractManager::__construct()`.

- Aucun changement de comportement observable hors fix #35 :
routes, templates, flux HTTP et logique métier inchangés.

### Tests

- Unit : 103 → 115 tests (+12), 350 assertions.
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 203 tests verts** (115 Unit + 88 Integration).

### Added (tests)

- **`TestableControleController`** : sous-classe de test qui
injecte 4 managers mockés et surcharge les factories
correspondantes.
- **`TestableControleControllerBuilder`** : builder de test qui
masque la complexité du constructeur et fournit des mocks
silencieux par défaut.
- **12 nouveaux tests Unit** :
- `ControleLigneStatutTest` (5 tests) : intégrité des 4 cases,
`tryFrom()` valide et invalide, `label()`, `isTerminal()`.
- `ControleControllerHandlersTest` (7 tests) :
- `updateLigne` : statut invalide (rejet + pas de save),
statut valide (save avec la bonne valeur), statut vide
(rejet + pas de save).
- `cloturer` : statut hors enum (log + statut inchangé +
libération), mapping des 3 statuts valides
(`hors_service→2`, `controle_ko→1`, `controle_ok→0`)
via dataProvider.


## [0.17.19] — 2026-10-06

### Fixed

- **AppUpdateController** : les Releases GitHub **sans description**
(`body: null`) ne sont plus rejetées (issue #45).
- Cause : `isset($release['body'])` retourne `false` quand la clé
existe mais vaut `null`. Le contrôleur en déduisait à tort
« Impossible de contacter GitHub. »
- Fix : `GitHubReleaseProvider` accepte `body: null` et le convertit
en chaîne vide (`body: ''`).
- Effet : plus besoin d'ajouter manuellement un body aux Releases
GitHub pour que l'updater fonctionne.

- **AppUpdateController** : suppression de `curl_close()` dans
`GitHubReleaseProvider` (déprécié en PHP 8.5, no-op depuis 8.0).

### Changed

- **AppUpdateController** : extraction des appels réseau vers un
nouveau service `GitHubReleaseProvider` (Vague 10 du chantier
« injection de dépendances », issue #45) :
- `GitHubReleaseProviderInterface` : contrat pour
`getLatestRelease()` et `downloadUrl()`.
- `GitHubReleaseProvider` : implémentation concrète avec cache TTL,
fallback cURL, et logs de diagnostic (HTTP code, JSON invalide).
- Factory `protected githubReleaseProvider()` dans
`AppUpdateController`, surchargeable en test.
- Suppression des méthodes privées `getLatestRelease()` et
`downloadUrl()` (déplacées dans le service).

- Aucun changement de comportement observable hors fix #45 :
routes, templates, flux HTTP et logique métier inchangés.

### Added

- **`TestableAppUpdateController`** : sous-classe de test qui injecte
un `GitHubReleaseProviderInterface` mocké.

- **9 nouveaux tests Unit** :
- `GitHubReleaseProvider` (5 tests) : cache frais, cache expiré,
cache sans body, cache corrompu, `downloadUrl` sur URL invalide.
- `AppUpdateController::index()` (4 tests) : release plus récente,
release sans body (fix #45), provider null, version identique.

### Tests

- Unit : 94 → 103 tests (+9), 306 assertions.
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 191 tests verts** (103 Unit + 88 Integration).

## [0.17.18] — 2026-10-06

### Changed

- **AcquisitionProcess** : extraction de 5 factories `protected`
pour les managers internes (Vague 9 du chantier
« injection de dépendances », issue #46) :
- `acquisitionManager()`
- `acquisitionLigneManager()`
- `fournisseurManager()`
- `categorieManager()`
- `equipementManager()`
- Tous les `new XxxManager()` en dur dans les 4 méthodes publiques
(`acquisition_process`, `categorie_process`,
`create_equipement_process`, `validerAcquisition`) sont remplacés
par des appels à ces factories.
- Objectif : rendre ces dépendances **mockables** en test unitaire
et supprimer les connexions PDO déclenchées par
`AbstractManager::__construct()` lors de l'exécution de
`AcquisitionProcess`.

- Aucun changement de comportement observable : routes, templates,
flux HTTP et logique métier inchangés.

### Added

- **`TestableAcquisitionProcess`** : sous-classe de test qui accepte
les 5 managers mockés en constructeur et surcharge les factories
correspondantes.

- **19 nouveaux tests Unit** sur `AcquisitionProcess` :
- `acquisition_process()` : réutilisation fournisseur existant,
création si inconnu, application des defaults, cast en int.
- `categorie_process()` : réutilisation catégorie existante,
création avec `ucfirst`, retour id du manager.
- `create_equipement_process()` : ligne introuvable, ligne déjà
générée, `regrouper_en_lot` (1 vs N), suffixe aléatoire sur
collision de code, `est_epi` hérité de la catégorie (ou défaut 1).
- `validerAcquisition()` : skip si toutes lignes générées,
génération pour les lignes non générées, marquage
`est_validee=1`, gestion multi-lignes, cas acquisition introuvable.

### Tests

- Unit : 75 → 94 tests (+19), 286 assertions.
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 182 tests verts** (94 Unit + 88 Integration).

## [0.17.17] — 2026-10-06

### Tests

- **+5 tests Unit** sur les handlers d'`AcquisitionController`
(palier 70 → 75, cible haute de l'issue #43) :
- `handleCreateAction` : `facture_date` manquante (`null` remonté),
`fournisseur_nom` vide (passe quand même à
`acquisitionProcess->acquisition_process()`).
- `handleUpdateAction` : upload OK sans ancienne facture
(`uploader->delete()` non appelé), `fournisseur_nom` inconnu
(`fournisseurManager->save()` appelé).
- `handleDeleteAction` : `facture_document` présent
(`uploader->delete()` appelé une fois).

- Aucun changement de code prod. Iso-comportement strict :
Integration 88 inchangés.
- Unit : 70 → 75 tests (+5), 217 assertions.
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 163 tests verts** (75 Unit + 88 Integration).

## [0.17.16] — 2026-10-06

### Changed

- **AcquisitionController** : extraction de 6 factories `protected`
pour les managers et le process (Vague 8 du chantier
« injection de dépendances ») :
- `acquisitionManager()`
- `acquisitionLigneManager()`
- `fournisseurManager()`
- `categorieManager()`
- `equipementManager()`
- `acquisitionProcess()`
- Tous les `new XxxManager()` / `new AcquisitionProcess()` en dur
dans les handlers et méthodes publiques (`list`, `create`,
`update`, `show`, `valider`, `delete`,
`handleCreateAction`, `handleUpdateAction`,
`handleAddLigneAction`, `handleDeleteAction`) sont remplacés
par des appels à ces factories.
- Objectif : rendre ces dépendances **mockables** en test unitaire
et supprimer les connexions PDO déclenchées par
`AbstractManager::__construct()` lors de l'exécution des
handlers.

- Aucun changement de comportement observable : routes, templates,
flux HTTP et logique métier inchangés.

### Added

- **`TestableAcquisitionController`** accepte désormais 9 paramètres
(Session, FactureUploader, Validator, 5 managers, AcquisitionProcess)
et surcharge les 8 factories correspondantes.

- **`TestableAcquisitionControllerBuilder`** :
nouveau builder de test qui masque la complexité du constructeur
et fournit des mocks silencieux par défaut.
Utilise `MockBuilder` directement car `createMock()` est `protected`
en PHPUnit 11.

- **15 nouveaux tests Unit** sur les handlers, couvrant les scénarios
jusqu'ici bloqués par l'ouverture PDO :
- `handleCreateAction` : upload OK, BAD_MIME, TOO_LARGE,
référence dupliquée, process qui throw, process qui retourne 0.
- `handleUpdateAction` : upload OK + suppression ancienne facture,
upload KO + ancienne préservée, échec `save()`, référence
dupliquée d'une autre acquisition.
- `handleAddLigneAction` : redirect sur succès,
`DuplicateReferenceException`.
- `handleDeleteAction` : brouillon OK (lignes + acquisition),
échec `delete()` (flash erreur), refus si équipements générés.

### Tests

- Unit : 55 → 70 tests (+15), 196 assertions.
- Integration : 88 tests (inchangés), 165 assertions.
- **Total : 158 tests verts** (70 Unit + 88 Integration).

## [0.17.15] — 2026-10-04

### Changed

- **FactureUploader** : introduction de
`Epiclub\Engine\FactureUploaderInterface` (Vague 8 du chantier
« injection de dépendances »).
- `FactureUploader` implémente désormais cette interface
(la classe reste `final`).
- `AcquisitionController::factureUploader()` retourne l'interface
au lieu de la classe concrète.
- Objectif : rendre le service **mockable** en test unitaire.
Les tests peuvent simuler les cas d'erreur (`BAD_MIME`,
`TOO_LARGE`, `IO_ERROR`, `INVALID`) sans dépendre du système
de fichiers.

- **AcquisitionValidator** : introduction de
`Epiclub\Domain\AcquisitionValidatorInterface`.
- `AcquisitionValidator` implémente cette interface
(la classe reste `final`).
- `AcquisitionController::validator()` retourne l'interface.
- Objectif : découpler les handlers du validator concret, et
permettre des tests Unit **sans BDD** ni dépendance à
`AcquisitionLigneManager` / `AcquisitionManager` /
`AcquisitionProcess`.

- Aucun changement de comportement observable : routes, templates,
flux HTTP et logique métier inchangés.

### Added

- `app/Engine/FactureUploaderInterface.php` : contrat du service
d'upload (méthodes `upload()`, `delete()`).
- `app/Domain/AcquisitionValidatorInterface.php` : contrat du
validator (méthodes `validateLigne()`, `performValidation()`).
- `tests/Unit/Controller/AcquisitionControllerHandlersTest.php` :
2 nouveaux tests sur `handleAddLigneAction` avec un mock
`AcquisitionValidatorInterface` :
- `testHandleAddLigneActionReturnsValidationErrorsFromValidator`
(erreurs remontées + `ligneData` préservée)
- `testHandleAddLigneActionReturnsLigneDataOnEmptyReference`
(court-circuit avant appel validator)

### Tests

- Unit : 53 → 55 tests (+2), 125 assertions.
- Integration : 88 tests (inchangés), 164 assertions.
- **Total : 143 tests verts** (55 Unit + 88 Integration).

### Notes

- L'objectif complet de la Vague 8 (tests Unit exhaustifs des
cas d'erreur upload) est **partiellement atteint** : les
interfaces sont en place, mais les handlers appellent encore
`new AcquisitionManager()` / `new FournisseurManager()` en dur,
ce qui empêche de tester les chemins qui traversent la BDD.
- Une **Vague 9** est prévue pour introduire l'injection de
dépendances complète (factories `protected` pour
`AcquisitionManager`, `FournisseurManager`, etc.), débloquant
ainsi les tests Unit exhaustifs.

## [0.17.14] — 2026-10-04

### Changed

- **AcquisitionController** : introduction du DTO
`Epiclub\Engine\HandlerResult` (Vague 7 du chantier #34).
Les 4 handlers extraits dans les vagues 3, 4 et 6 abandonnent la
convention `?Response` + mutation par référence (`&$acquisition`,
`&$form_errors`, `&$ligneData`) au profit d'un objet immuable
(`readonly`) qui porte le contrat de manière explicite :

| Handler | Avant | Après |
|---|---|---|
| `handleCreateAction` | `?Response` + `&$acquisition`, `&$form_errors` | `HandlerResult` |
| `handleUpdateAction` | `?Response` + `&$acquisition`, `&$form_errors` | `HandlerResult` |
| `handleAddLigneAction` | `?Response` + `&$form_errors`, `&$ligneData` | `HandlerResult` |
| `handleDeleteAction` | `Response` (retour direct) | `HandlerResult` |

- Le DTO expose 4 propriétés publiques readonly :
- `response` (`?Response`) — redirection immédiate si non-null,
sinon rendu du formulaire.
- `acquisition` (`array`) — acquisition mise à jour (whitelist,
`fournisseur_id`, `facture_document`…), remontée **même en cas
d'erreur** pour que le template réaffiche la saisie.
- `formErrors` (`array`) — erreurs indexées par champ.
- `ligneData` (`array`) — données de ligne saisies (add_ligne).
- `isRedirect()` : `true` si `response` est non-null.

- `create()`, `update()` et `delete()` adaptés pour consommer le DTO
(`$result->isRedirect()`, `$result->response`, `$result->acquisition`,
`$result->formErrors`, `$result->ligneData`). Le cas `valider` de
`update()` reste hors du match DTO car `performValidation()` retourne
directement une `Response`.

- Les 4 handlers passent de `private` à `protected` pour permettre la
surcharge dans une sous-classe de test
(`TestableAcquisitionController`). Changement de visibilité
iso-comportement, motivé par la testabilité.

- Aucun changement de comportement observable : routes, templates,
managers, validators et flux HTTP inchangés.

### Added

- `app/Engine/HandlerResult.php` : DTO immuable portant le contrat
des handlers.
- `tests/Unit/Engine/HandlerResultTest.php` : 11 tests (constructeur,
valeurs par défaut, `isRedirect()` sur `RedirectResponse` et
`Response` simple, immutabilité des 4 propriétés).
- `tests/Unit/Controller/AcquisitionControllerHandlersTest.php` :
4 tests des handlers couvrant les cas qui court-circuitent avant tout
appel BDD (référence vide, whitelist, absence d'appel à
`FactureUploader::upload()`, `handleDeleteAction` sur acquisition
validée).
- `tests/Unit/Controller/Support/TestableAcquisitionController.php` :
sous-classe de test exposant les handlers protégés et injectant un
`FactureUploader` réel (classe `final`, non mockable) sur un
répertoire temporaire.

### Tests

- Unit : 38 → 53 tests (+15), 115 assertions.
- Integration : 88 tests (inchangés), 164 assertions.
- **Total : 141 tests verts** (53 Unit + 88 Integration).

### Notes

- `FactureUploader` et `AcquisitionValidator` sont `final` et ne
peuvent donc pas être mockés par PHPUnit. Les tests Unit utilisent
des instances réelles avec répertoire temporaire pour le premier,
et court-circuitent le second (jamais appelé dans les scénarios
testés).
- Les scénarios qui nécessitent la BDD (référence dupliquée, échec
`save()`) restent couverts par la suite Integration existante.

## [0.17.13] — 2026-10-03

### Changed

- **AcquisitionController** : extraction effective de `handleDeleteAction()`
depuis `delete()` (Vague 6), en symétrie avec les handlers extraits
dans les vagues 3 et 4. Le handler retourne toujours une `Response`
(pas de rendu de formulaire en cas d'erreur sur cette action).
`delete()` public ne fait plus que le pré-vol (permissions, CSRF,
existence) puis délègue. Aucun changement de comportement.

### Fixed

- **Release v0.17.12** : le refactor `handleDeleteAction` n'avait pas
été commité avant le tag. Le tag `v0.17.12` ne contient que le
CHANGELOG et `version.txt`. Le code est livré ici en v0.17.13. 

## [0.17.12] — 2026-10-03

> ⚠️ **Release incomplète** : le refactor `handleDeleteAction` annoncé
> dans cette release a été livré dans la v0.17.13. Seul le CHANGELOG et
> `version.txt` ont été taggés ici par erreur.

### Changed

- **AcquisitionController** : extraction de `handleDeleteAction()` depuis
`delete()` (Vague 6). Voir v0.17.13 pour le détail.
## [0.17.11] — 2026-10-03

### Changed

- **FactureUploader** : extraction du service d'upload de factures
(`AcquisitionController::uploadFacture()` et helpers associés) vers
`Epiclub\Engine\FactureUploader` (issue #40, Étape A).
- Le chemin d'uploads est injecté au constructeur.
- Les erreurs remontent via `FactureUploadException` (codes
`TOO_LARGE`, `BAD_MIME`, `IO_ERROR`, `INVALID`) au lieu de
`$lastUploadError` (état mutable local).
- `delete()` centralise la suppression best-effort des fichiers.
- Aucun changement de comportement observable côté utilisateur.

### Tests

- Unit : 28 → 38 tests (+10 sur `FactureUploader`), 80 assertions.
- Integration : 88 tests (inchangés).

## [0.17.10] — 2026-10-03

### Security

- **CSRF** : extraction de `AbstractController::validateCsrf()` vers
`Epiclub\Engine\CsrfValidator`, couverte par 9 tests unitaires.
Toute suppression accidentelle du `throw` (régression 0361ec6) fera
désormais échouer les tests. Aucun changement de comportement.

### Tests

- Unit : 19 → 28 tests (+9), 62 assertions.

## [0.17.9] — 2026-10-03

### Changed

- **AcquisitionController** : refactor des actions POST `create` et `update`
  (Vagues 3 et 4 du chantier #34).
  - `create()` et `update()` passent d'environ 150 lignes de `if/else`
    imbriqués à un dispatch `match($action)` de ~35 lignes chacun.
  - Extraction de trois handlers privés suivant une convention uniforme
    `?Response` (`Response` = redirection immédiate, `null` = rendu du
    formulaire avec erreurs) :
    - `handleCreateAction()` (depuis `create()`)
    - `handleUpdateAction()` (depuis `update()`)
    - `handleAddLigneAction()` (depuis `update()`)
  - `validateCsrf()` et `isPostTruncated()` restent dans les méthodes
    publiques, **avant** le dispatch, pour préserver leur sémantique
    (throw / court-circuit sur POST tronqué).
  - Comportement inchangé : 88 tests d'intégration verts, 165 assertions.
    Aucune modification des routes, templates, managers ni validators.