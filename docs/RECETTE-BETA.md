# EPIClub — Fiche de recette bêta (v1.0.0-beta.3)

**Instance de test** : *(à compléter : URL)*
**Testeur(s)** : *(à compléter)*
**Période** : *(à compléter)*

> **Mode d'emploi** : dérouler les scénarios dans l'ordre. Cocher ✅ si le
> résultat observé correspond au résultat attendu, ❌ sinon en notant le
> problème dans la colonne « Observations » (capture d'écran bienvenue).
> Les scénarios marqués 🧭 sont des parcours de bout en bout : ils
> enchaînent plusieurs écrans comme le ferait un vrai utilisateur.
>
> **Avant de commencer** : vérifier en pied de page que l'instance est
> bien en **1.0.0-beta.3** et que le canal de mise à jour est sur
> **Bêta** (Administration → Paramètres système).

---

## 1. Rôles et accès

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 1.1 | Se connecter avec un compte **utilisateur** | Accès au tableau de bord, pas de menu Administration | ☐ | |
| 1.2 | Se connecter avec un compte **contrôleur** | Menu Contrôles accessible, pas d'accès Administration | ☐ | |
| 1.3 | Se connecter avec un compte **super-admin** | Menus Administration complets (Paramètres système, Mise à jour) | ☐ | |
| 1.4 | Utilisateur non connecté → URL directe d'une page protégée | Redirection vers la page de connexion, puis retour sur la page demandée après login | ☐ | |

## 2. 🧭 Scan d'un QR code avec l'appareil photo du téléphone

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 2.1 | **Utilisateur** scanne le QR d'un équipement | Arrive sur la **fiche équipement** | ☐ | |
| 2.2 | **Contrôleur sans contrôle ouvert** scanne un QR | Arrive sur la **fiche équipement** (pas la fiche contrôle) | ☐ | |
| 2.3 | **Contrôleur avec contrôle ouvert** scanne un QR | L'équipement est **ajouté au contrôle** et il arrive sur la **fiche contrôle** | ☐ | |
| 2.4 | Contrôleur : re-scan du même équipement (doublon) | Pas de doublon créé ; message explicite | ☐ | |

## 3. 🧭 Scan intégré (menu Contrôles → « Scanner un équipement »)

⚠️ Nécessite HTTPS (ou localhost) — la caméra est bloquée sinon.

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 3.1 | Ouvrir la page de scan **avec un contrôle ouvert** | La caméra s'active, le scan reste **dans la page** (pas de nouvel onglet) | ☐ | |
| 3.2 | Scanner plusieurs équipements à la suite | Chaque scan ajoute l'équipement au contrôle (confirmation visuelle + sonore), la liste des scannés s'allonge | ☐ | |
| 3.3 | Scanner un équipement déjà ajouté | Signalé comme doublon, pas de ligne créée | ☐ | |
| 3.4 | Cliquer « Terminer » | Arrive sur la **fiche contrôle** avec tous les équipements scannés | ☐ | |
| 3.5 | Ouvrir la page de scan **sans contrôle ouvert** et scanner | Redirection (même onglet) vers la **fiche équipement** | ☐ | |

## 4. Contrôles

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 4.1 | Ouvrir un nouveau contrôle | Le contrôle apparaît comme « ouvert » | ☐ | |
| 4.2 | Depuis la fiche contrôle, ajouter un équipement **via la liste** | L'équipement est ajouté **et** la fiche contrôle de l'équipement s'ouvre directement | ☐ | |
| 4.3 | Renseigner le résultat du contrôle d'un équipement (conforme / non conforme, commentaire) | Enregistré, visible dans la fiche contrôle et l'historique de l'équipement | ☐ | |
| 4.4 | Clôturer le contrôle | Le contrôle passe en « terminé », non modifiable ensuite | ☐ | |

## 5. 🧭 Import Excel (fichier réel du club)

Fichier de référence : `EPI au 25 juin 2026.xlsx` (fichier réel fourni).

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 5.1 | Lancer l'import et charger le fichier | La ligne d'en-tête est détectée automatiquement (même si ce n'est pas la ligne 1) | ☐ | |
| 5.2 | Écran de **mapping des colonnes** | Chaque colonne du fichier peut être associée à un champ ; vérifier les correspondances proposées | ☐ | |
| 5.3 | **Lignes non conformes** (cases fusionnées, données incompatibles) | Affichées **en entier** avant l'import avec l'avertissement qu'elles seront ignorées ; présentes dans le rapport final | ☐ | |
| 5.4 | **Catégories inconnues** du fichier | Pour chacune : proposition de rattachement à une catégorie existante **ou** création d'une nouvelle | ☐ | |
| 5.5 | **Dates** : année seule (ex. « 2019 ») | Date d'achat → 1er janvier de l'année ; date de péremption → 31 décembre ; case vide → date du jour pour l'achat | ☐ | |
| 5.6 | **Lots** : ligne avec un nombre > 1 | Un lot est créé ; la fiche club-équipement indique le **nombre initial** d'objets | ☐ | |
| 5.7 | **Groupement en acquisitions** : équipements d'une même facture → une acquisition auto-validée ; sans facture → regroupés par date d'achat (même année seule) | Les acquisitions créées correspondent ; chacune porte un fichier facture « facture à importer » | ☐ | |
| 5.8 | **Rapport d'import** final | Liste les équipements créés, les lignes ignorées (complètes), les acquisitions créées | ☐ | |
| 5.9 | Référence automatique des équipements importés sans référence | Format `YY + code catégorie + n° d'ordre` (ex. `26COR001` pour la première corde de 2026) | ☐ | |

## 6. Lots

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 6.1 | Ouvrir la fiche d'un équipement importé en lot | Le **nombre initial** est renseigné ; le nombre courant est modifiable | ☐ | |
| 6.2 | Essayer de dépasser le nombre initial | Refusé avec message explicite | ☐ | |
| 6.3 | Liste des équipements (template `equipement_list.twig`) | Le nombre est affiché pour les lots (badge « Lot : X/Y ») ; la liste n'est **pas vide** | ☐ | |
| 6.4 | La référence d'un lot ne contient **pas** « LOT » | Conforme | ☐ | |

## 7. Fiches équipement et catégories

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 7.1 | Fiche équipement : case **« Durée maximum d'utilisation »** | Pré-cochée selon la valeur par défaut de la catégorie ; modifiable sur la fiche | ☐ | |
| 7.2 | Écran catégorie : saisir un libellé | Le **code** est pré-rempli automatiquement (3 premières lettres, ex. `COR` pour cordes) ; l'**aperçu de référence** s'affiche en direct (ex. `26COR001`) — sans confusion : le code est `COR`, pas `26COR001` | ☐ | |
| 7.3 | Créer une acquisition validée → équipement créé | La **référence** est générée automatiquement à la validation selon le modèle | ☐ | |

## 8. 🧭 Export / réimport (Excel et CSV)

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 8.1 | Exporter les équipements en **Excel** | Fichier téléchargé, s'ouvre correctement | ☐ | |
| 8.2 | Exporter en **CSV** | Fichier correct, délimiteur détecté au réimport | ☐ | |
| 8.3 | Réimporter le fichier exporté (base de test) | Mapping automatique, pas d'erreur | ☐ | |

## 9. Mises à jour applicatives

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 9.1 | Administration → Mise à jour | Le **canal actif** est affiché (badge beta jaune) ; la dernière version GitHub s'affiche | ☐ | |
| 9.2 | Administration → Paramètres système → basculer Stable ↔ Bêta | Persisté ; le badge de la page Mise à jour change en conséquence | ☐ | |
| 9.3 | À la prochaine bêta publiée : vérifier la proposition de mise à jour | La nouvelle bêta est détectée (cache 1 h — supprimer `var/tmp/update/latest_release.json.beta` pour forcer) | ☐ | |

## 10. QR codes (génération)

| # | Scénario | Résultat attendu | OK | Observations |
|---|---|---|---|---|
| 10.1 | Générer/imprimer le QR d'un équipement depuis sa fiche | QR généré et lisible par un téléphone | ☐ | |
| 10.2 | QR d'un équipement supprimé ou inconnu | Message d'erreur propre (pas de page blanche) | ☐ | |

---

## Bilan

- **Bloquants** (empêchent l'usage) : …
- **Gênants** (contournables) : …
- **Cosmétiques** : …

**Décision de fin de recette** : ☐ GO pour la 1.0.0 stable  ☐ nouvelle bêta nécessaire
