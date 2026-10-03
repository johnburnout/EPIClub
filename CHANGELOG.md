# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s'inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)
et le projet adhère au [versionnage sémantique](https://semver.org/lang/fr/).

Les versions antérieures à 0.17.9 ne sont pas documentées ici ; se référer
à l'historique Git (`git log`, `git tag`).

## [Unreleased]

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