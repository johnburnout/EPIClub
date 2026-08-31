<img src="/assets/img/EPIClub.png" alt="Logo EPIClub" width="200" height="200">

# Guide d'utilisation – EPIClub

## Présentation
EPIClub est une application de gestion d'équipements pour les clubs sportifs et associations.

---

## 1. Gestion des équipements

### Principe
Les équipements ne sont **pas saisis directement**.  
Ils sont générés automatiquement à partir des **factures d'acquisition** validées.

---

### 1.1 Créer une acquisition (facture)

**Rôle requis :** `ROLE_ADMIN`

1. Aller dans le menu **Administration → Acquisitions**.
2. Cliquer sur **« Nouvelle acquisition »**.
3. Renseigner :
- **Fournisseur** (sélectionner un fournisseur existant ou le créer)
- **Référence de la facture**
- **Date de la facture**
- **Document** (PDF, image, etc. – optionnel)
4. Dans la même page, ajouter **une ou plusieurs lignes** d’équipements :
- **Référence** (ex: `CHAISE-001`)
- **Désignation** (ex: « Chaise de bureau »)
- **Catégorie** (ex: « Mobilier »)
- **Nombre** (quantité achetée)
- Cocher **« Regrouper en lot »** si les équipements doivent être identiques (même référence, même code).

---

### 1.2 Valider l’acquisition

1. Une fois la facture saisie, cliquer sur **« Valider »**.
2. **Action automatique** :
- Pour chaque ligne, le système crée **autant d’équipements que le nombre indiqué**.
- Chaque équipement reçoit :
- Une **référence** (identique à la ligne)
- Un **libellé** (identique à la désignation)
- Un **code** unique (généré automatiquement)
- La **catégorie** et le **statut** par défaut « Disponible »
- Une **photo** vide (modifiable ultérieurement)

3. L’acquisition passe en statut **« Validée »** et n’est plus modifiable.

---

### 1.3 Modifier un équipement (après validation)

**Rôle requis :** `ROLE_ADMIN`

1. Depuis la **liste des équipements**, cliquer sur **« Modifier »** pour un équipement.
2. Vous pouvez alors :
- Ajouter ou modifier la **photo** (redimensionnée automatiquement)
- Changer l’**emplacement**
- Modifier les **remarques**
- Mettre à jour les **dates** (mise en service, fin d’utilisation)
- Changer le **statut** (Disponible, En maintenance, Hors service)
- Changer l’**état d’usure** (si défini)

> **Important :** la référence, le code et la catégorie ne sont **pas modifiables** car ils sont hérités de l’acquisition.

---

### 1.4 Consulter / Exporter

- **Fiche individuelle** : depuis la liste, cliquer sur **« Voir »** pour consulter toutes les informations (y compris l’historique des contrôles et la facture associée).
- **PDF individuel** : depuis la fiche, cliquer sur **« PDF »**.
- **PDF liste / Excel / Étiquettes** : depuis la liste, utiliser le menu déroulant **« Exporter »**.

---

## 2. Contrôles

### Créer un contrôle
- Réservé aux utilisateurs avec le rôle **Contrôleur**.
- Depuis la fiche d'un équipement, cliquez sur **Démarrer un contrôle**.

### Scanner un QR Code
- Le QR Code d'un équipement redirige vers la fiche ou vers un contrôle en cours.
- Si un contrôle est en cours, l'équipement est automatiquement ajouté à la ligne de contrôle.

---

## 3. Gestion des utilisateurs
- Les administrateurs peuvent créer, modifier et supprimer des utilisateurs.
- Les rôles disponibles : `ROLE_USER`, `ROLE_CONTROLLEUR`, `ROLE_ADMIN`.

---

## 4. Réinitialisation du mot de passe
- Depuis la page de connexion, cliquez sur **Mot de passe oublié**.
- Un email avec un lien de réinitialisation (valable 24h) est envoyé.

---

## 5. Administration

### Catégories
- Définir des catégories d'équipements (avec possibilité de marquer un équipement comme EPI).

### Emplacements
- Définir des emplacements physiques (lieu de stockage, rayonnage, etc.).

### Acquisitions
- Gérer les achats d'équipements avec factures et génération automatique des équipements.

---

## Support
En cas de problème, contactez l'administrateur du club.