
DROP TABLE IF EXISTS `acquisition`;
CREATE TABLE IF NOT EXISTS `acquisition` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fournisseur_id` int(11) NOT NULL,
  `facture_reference` varchar(32) NOT NULL,
  `facture_date` date NOT NULL,
  `facture_document` varchar(255) NULL DEFAULT '',
  `saisie_par` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facture_reference` (`facture_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `acquisition_ligne`;
CREATE TABLE IF NOT EXISTS `acquisition_ligne` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `acquisition_id` int(11) NOT NULL,
  `reference` varchar(64) NOT NULL,
  `equipement_designation_id` int(11) NOT NULL,
  `nombre` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `equipement_categorie`;
CREATE TABLE IF NOT EXISTS `equipement_categorie` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `libelle` varchar(32) NOT NULL,
  `description` TEXT NULL,
  `image` varchar(255) NULL DEFAULT '',
  `est_epi` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `equipement_designation`;
CREATE TABLE IF NOT EXISTS `equipement_libelle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categorie_id` int(11) NOT NULL,
  `libelle` varchar(64) NOT NULL,
  `description` TEXT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `club`;
CREATE TABLE IF NOT EXISTS `club` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(64) NOT NULL,
  `activite` varchar(64) NOT NULL,
  `description` TEXT NULL,
  `email` varchar(255) NULL DEFAULT '',
  `phone` varchar(16) NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `club_equipement`;
CREATE TABLE IF NOT EXISTS `club_equipement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `acquisition_id` int(11) NOT NULL,
  `categorie_id` int(11) NOT NULL,
  `equipement_libelle_id` int(11) NOT NULL,
  `reference` varchar(255) NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT 0,
  `remarques` TEXT NULL,
  `date_dernier_controle` date NULL,
  `controle_en_cours` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `club_equipment_controle`;
CREATE TABLE IF NOT EXISTS `club_equipment_controle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `controleur_id` int(11) NOT NULL,
  `club_equipement_id` int(11) NOT NULL,
  `etat` varchar(32) NOT NULL,
  `remarques` text NULL,
  `date_controle` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `fournisseur`;
CREATE TABLE IF NOT EXISTS `fournisseur` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(64) NOT NULL,
  `email` varchar(255) NULL DEFAULT '',
  `phone` varchar(16) NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `fournisseur` (`id`, `nom`,`email`, `phone`) VALUES
(1, 'Fournisseur 1', NULL, NULL),
(2, 'Fournisseur 2', NULL, NULL);
