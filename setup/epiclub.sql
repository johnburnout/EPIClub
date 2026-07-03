-- =============================================
-- 1. UTILISATEUR (défini en premier car référencé par controle)
-- =============================================
DROP TABLE IF EXISTS `utilisateur`;
CREATE TABLE IF NOT EXISTS `utilisateur` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(32) NOT NULL,
  `prenom` varchar(32) NOT NULL,
  `username` varchar(32) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(32) NOT NULL,
  `date_creation` datetime NOT NULL,
  `derniere_connexion` datetime NULL,
  `last_activity` datetime NULL,   -- ⬅️ AJOUT
  `controle_en_cours_id` int(11) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  KEY `controle_en_cours_id` (`controle_en_cours_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- 2. FOURNISSEUR
-- =============================================
DROP TABLE IF EXISTS `fournisseur`;
CREATE TABLE IF NOT EXISTS `fournisseur` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(64) NOT NULL,
  `email` varchar(255) NULL DEFAULT '',
  `phone` varchar(16) NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `fournisseur` (`id`, `nom`, `email`, `phone`) VALUES
(1, 'Fournisseur 1', NULL, NULL),
(2, 'Fournisseur 2', NULL, NULL);

-- =============================================
-- 3. CATEGORIE
-- =============================================
DROP TABLE IF EXISTS `categorie`;
CREATE TABLE IF NOT EXISTS `categorie` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `libelle` varchar(32) NOT NULL,
  `description` TEXT NULL,
  `image` varchar(255) NULL DEFAULT '',
  `est_epi` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- 4. EMPLACEMENT
-- =============================================
DROP TABLE IF EXISTS `emplacement`;
CREATE TABLE IF NOT EXISTS `emplacement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `libelle` varchar(64) NOT NULL,
  `description` TEXT NULL,
  `image` varchar(255) NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- 5. CLUB
-- =============================================
DROP TABLE IF EXISTS `club`;
CREATE TABLE IF NOT EXISTS `club` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(64) NOT NULL,
  `activite` varchar(64) NOT NULL,
  `description` TEXT NULL,
  `email` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(16) NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- 6. ACQUISITION (UNIQUE)
-- =============================================
DROP TABLE IF EXISTS `acquisition`;
CREATE TABLE IF NOT EXISTS `acquisition` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fournisseur_id` int(11) NOT NULL,
  `facture_reference` varchar(32) NOT NULL,
  `facture_date` date NOT NULL,
  `facture_document` varchar(255) NULL DEFAULT '',
  `saisie_par` int(11) NOT NULL,
  `est_validee` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facture_reference` (`facture_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- 7. ACQUISITION_LIGNE
-- =============================================
DROP TABLE IF EXISTS `acquisition_ligne`;
CREATE TABLE IF NOT EXISTS `acquisition_ligne` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `acquisition_id` int(11) NOT NULL,
  `reference` varchar(32) NOT NULL,
  `designation` varchar(64) NOT NULL,
  `categorie_id` int(11) NOT NULL,
  `nombre` int(11) NOT NULL,
  `equipements_generes` tinyint(1) NOT NULL DEFAULT 0,
  `regrouper_en_lot` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- 8. CLUB_EQUIPEMENT
-- =============================================
DROP TABLE IF EXISTS `club_equipement`;
CREATE TABLE IF NOT EXISTS `club_equipement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `acquisition_id` int(11) NOT NULL,
  `categorie_id` int(11) NOT NULL,
  `reference` varchar(32) NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `code` varchar(255) NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT 0,
  `remarques` TEXT NULL,
  `date_dernier_controle` date NULL,
  `controle_en_cours` tinyint(1) NOT NULL DEFAULT 0,
  `emplacement_id` int(11) NULL,
  `date_mise_en_service` DATE NULL,
  `date_fin_utilisation` DATE NULL,
  `nombre` int(11) NOT NULL DEFAULT 1,
  `est_epi` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- 9. CONTROLE (après utilisateur)
-- =============================================
DROP TABLE IF EXISTS `controle`;
CREATE TABLE IF NOT EXISTS `controle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `libelle` varchar(64) NOT NULL,
  `date_debut` datetime NOT NULL,
  `date_fin` datetime NULL,
  `statut` enum('ouvert','en_cours','cloture') NOT NULL DEFAULT 'ouvert',
  `controleur_id` int(11) NOT NULL,
  `cree_par` int(11) NOT NULL,
  `hash_remarques` varchar(64) NULL,
  PRIMARY KEY (`id`),
  KEY `controleur_id` (`controleur_id`),
  KEY `cree_par` (`cree_par`),
  CONSTRAINT `fk_controle_controleur` FOREIGN KEY (`controleur_id`) REFERENCES `utilisateur` (`id`),
  CONSTRAINT `fk_controle_cree_par` FOREIGN KEY (`cree_par`) REFERENCES `utilisateur` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Mise à jour de la clé étrangère dans utilisateur (après création de controle)
ALTER TABLE `utilisateur` ADD CONSTRAINT `fk_utilisateur_controle` FOREIGN KEY (`controle_en_cours_id`) REFERENCES `controle` (`id`) ON DELETE SET NULL;

-- =============================================
-- 10. CONTROLE_LIGNE
-- =============================================
DROP TABLE IF EXISTS `controle_ligne`;
CREATE TABLE IF NOT EXISTS `controle_ligne` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `controle_id` int(11) NOT NULL,
  `equipement_id` int(11) NOT NULL,
  `remarque` text NULL,
  `date_controle` datetime NULL,
  `statut` enum('a_controler','controle_ok','controle_ko','hors_service') NOT NULL DEFAULT 'a_controler',
  PRIMARY KEY (`id`),
  KEY `controle_id` (`controle_id`),
  KEY `equipement_id` (`equipement_id`),
  CONSTRAINT `fk_controle_ligne_controle` FOREIGN KEY (`controle_id`) REFERENCES `controle` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_controle_ligne_equipement` FOREIGN KEY (`equipement_id`) REFERENCES `club_equipement` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- 11. ANCIENNE TABLE équipement_controle (obsolète, on la garde vide)
-- =============================================
DROP TABLE IF EXISTS `equipement_controle`;
CREATE TABLE IF NOT EXISTS `equipement_controle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `controleur_id` int(11) NOT NULL,
  `equipement_id` int(11) NOT NULL,
  `etat` varchar(32) NOT NULL,
  `remarques` text NULL,
  `date_controle` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;