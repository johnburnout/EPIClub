# EPIClub — Fichier de reprise

**Dernière mise à jour** : 2026-10-13 (fin de session #69 — Vague 14 en cours)
**Version courante** : v0.18.9
**Branche** : `main` (travail direct sur main, pas de PR)

---

## État du projet

Application de gestion EPI (équipements de protection individuelle)
pour club d'escalade. PHP 8.5, Symfony components (HttpFoundation,
Mailer, Mime), Twig, MySQL, PHPUnit 11.

### Versions récentes

| Version | Ticket | Description | Commit |
|---|---|---|---|
| v0.18.9 | #69 | JournalManager — suppression getPdo() + fix ORDER BY | `<hash>` |
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

- **336 tests Unit/Domain** verts, 792 assertions.
- **7 tests Integration** (nécessitent serveur HTTP sur `localhost:8000`), skippés si serveur absent.
- Commande : `php vendor/bin/phpunit`
- Avec Integration : démarrer `php -S localhost:8000 -t public` dans un autre terminal.

---

## Prochaines cibles

### Vague 14 — en cours (refactors Engine)

- ✅ **#68** — `buildWhereClause()` dans `AbstractManager`.
- ✅ **#69** — `JournalManager` : suppression `getPdo()`, extraction
  `buildOrderByClause()`, fix du bug `ORDER BY` (500).
- Candidats suivants :
  - Fix `limit <= 0` dans `JournalManager` (`LIMIT -5` → 500).
  - Fix `'u.nom, u.prenom ASC'` (ambiguïté multi-colonnes).
  - Audit des requêtes SQL concaténées restantes
    (`EquipementManager::findOneByCriteria()`).
  - `AbstractManager::findOneByCriteria()` générique ? (attention à
    `EquipementManager` — cas particulier `deleted_at IS NULL` — et à
    l'hydratation spécifique d'`AcquisitionManager`).
  - Nouveaux tests Unit sur les `Manager` non couverts (ClubManager,
    EmplacementManager, FournisseurManager, CategorieManager, etc.).
  - Tests Unit sur les contrôleurs non encore couverts
    (AcquisitionController, IndexController, QrRedirectController,
    AppUpdateController, AppUserRegisterController — partiellement
    couverts).
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
- Les tests #68 et #69 (`AbstractManagerBuildWhereClauseTest`,
  `JournalManagerTest`) utilisent déjà l'attribut `#[DataProvider]` —
  pas d'ajout de dette.
- **Ticket à ouvrir** : oui, un petit ticket « chore: migrer
  @dataProvider vers #[DataProvider] ».

### Dette 5 — `ControleController` helpers en délégation

- `encryptRemarque` / `decryptRemarque` / `isEncrypted` délèguent à
  `EncryptionService` depuis #50b.
- Les helpers restent `protected` pour préserver les ~12 appels internes.
- Indirection légère, acceptable. Pas de ticket.

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

Serveur HTTP pour tests Integration :

- `php -S localhost:8000 -t public` (dans un second terminal).

Base de données de test :

- `mysql -u root epiclub -e "SELECT id, username, role FROM utilisateur WHERE username='admin';"`

Pattern release Git :

- `git add <fichiers>`
- `git commit -m "type(#ticket): description" -m "Closes #XX"`
- `git push origin main`
- `git tag -a vX.Y.Z -m "vX.Y.Z — #ticket description"`
- `git push origin vX.Y.Z`

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
  `CsrfValidatorTest`).
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
  sur `AbstractManager`.
- **Mocker `\PDO`** : PHPUnit 11 mocke bien `\PDO` et `\PDOStatement`
  (`createMock(\PDO::class)` fonctionne). Attention à deux pièges :
  - `method('fetch')->willReturn(['total' => 0])` **ne matche pas**
    `fetch(\PDO::FETCH_ASSOC)` (arguments stricts). Utiliser
    `willReturnCallback(fn() => [...])` pour matcher tout appel.
  - `willReturnCallback` sur `prepare()` doit discriminer le count SQL
    du data SQL. Ne pas se fier à `str_contains($sql, 'COUNT(*)')` :
    la requête principale peut contenir une sous-requête `COUNT(*)`.
    Discriminer sur `str_contains($sql, 'ORDER BY')` (uniquement
    présent dans le data SQL).

---

## Contexte de la session précédente

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
- **#65** (v0.18.7) : `FournisseurController` factory
  `fournisseurManager()` + 20 tests Unit (edit/delete/list/show,
  validation email souple). **Dernier ticket Vague 11**.
- **#64** (v0.18.6) : `EmplacementController` factory
  `emplacementManager()` + 27 tests Unit.
- **#63** (v0.18.5) : `ClubController` factory `clubManager()` + 20 tests
  Unit. Piège CSRF identifié (constante `CSRF_TOKEN` manquante).

**🎯 Vague 11 complète** (3 contrôleurs, 67 tests Unit).
**🎯 Vague 14 en cours** (#68 et #69 livrés).
**Prochaine session** : continuer la Vague 14 — fix `limit <= 0`
et ambiguïté multi-colonnes de `JournalManager` (Dette 7, même
pattern que #69), ou audit SQL restant, ou Dette 4 (rapide).