-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Aug 22, 2025 at 12:14 PM
-- Server version: 10.11.11-MariaDB-0+deb12u1
-- PHP Version: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `epi_PE`
--

-- --------------------------------------------------------

--
-- Table structure for table `categorie`
--

CREATE TABLE `categorie` (
  `id` int(11) NOT NULL,
  `libelle` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabricant`
--

CREATE TABLE `fabricant` (
  `id` int(11) NOT NULL,
  `libelle` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `facture`
--

CREATE TABLE `facture` (
  `id` int(11) NOT NULL,
  `vendeur` varchar(30) NOT NULL DEFAULT 'Boutique ?',
  `date_facture` date DEFAULT NULL,
  `en_saisie` tinyint(1) NOT NULL DEFAULT 1,
  `reference` varchar(15) DEFAULT NULL,
  `libelle` varchar(30) GENERATED ALWAYS AS (concat_ws(' ',`vendeur`,`date_facture`)) VIRTUAL,
  `utilisateur` varchar(30) NOT NULL DEFAULT '""'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `fiche`
-- (See below for the actual view)
--
CREATE TABLE `fiche` (
`id` int(11)
,`ref` varchar(8)
,`libelle` varchar(60)
,`en_service` tinyint(4)
,`categorie` varchar(20)
,`categorie_id` int(11)
,`fabricant` varchar(50)
,`fabricant_id` int(11)
,`affectation` varchar(15)
,`affectation_id` int(11)
,`facture` varchar(30)
,`facture_id` int(11)
,`date_facture` date
,`nb_elements` int(11)
,`date_max` date
,`date_debut` date
,`controle_id` int(11)
,`date_controle` date
,`remarques` text
,`photo` varchar(50)
,`nb_elements_initial` int(11)
,`en_controle` tinyint(1)
,`facture_en_saisie` tinyint(1)
);

-- --------------------------------------------------------

--
-- Table structure for table `affectation`
--

CREATE TABLE `affectation` (
  `id` int(11) NOT NULL,
  `libelle` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `affectation`
--

INSERT INTO `affectation` (`id`, `libelle`) VALUES
(1, 'En attente'),
(0, 'Hors-Service');

-- --------------------------------------------------------

--
-- Stand-in structure for view `liste`
-- (See below for the actual view)
--
CREATE TABLE `liste` (
`id` int(11)
,`ref` varchar(8)
,`libelle` varchar(60)
,`fabricant` varchar(50)
,`categorie` varchar(20)
,`categorie_id` int(11)
,`affectation` varchar(15)
,`affectation_id` int(11)
,`nb_elements` int(11)
,`date_controle` date
,`date_max` date
,`en_service` tinyint(4)
,`controle_id` int(11)
,`date_facture` date
,`facture_id` int(11)
);

-- --------------------------------------------------------

--
-- Table structure for table `matos`
--

CREATE TABLE `matos` (
  `id` int(11) NOT NULL,
  `reference` varchar(8) DEFAULT '00XXX000' COMMENT 'Référence du lot',
  `en_service` tinyint(4) NOT NULL DEFAULT 1,
  `libelle` varchar(60) DEFAULT NULL,
  `categorie_id` int(11) DEFAULT NULL,
  `fabricant_id` int(11) DEFAULT NULL,
  `facture_id` int(11) NOT NULL,
  `photo` varchar(50) DEFAULT 'null.jpeg',
  `affectation_id` int(11) DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_max` date DEFAULT NULL,
  `date_modification` date NOT NULL DEFAULT current_timestamp(),
  `nb_elements_initial` int(11) DEFAULT NULL,
  `nb_elements` int(11) DEFAULT NULL,
  `controle_id` int(11) DEFAULT 1,
  `utilisateur` varchar(30) DEFAULT '""',
  `remarques` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Triggers `matos`
--
DELIMITER $$
CREATE TRIGGER `nombre_elements` BEFORE INSERT ON `matos` FOR EACH ROW SET 
	NEW.nb_elements = NEW.nb_elements_initial,
    NEW.date_max = DATE_ADD(NEW.date_debut, INTERVAL 10 YEAR)
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `utilisateur`
--

CREATE TABLE `utilisateur` (
  `id` int(11) NOT NULL,
  `username` varchar(32) DEFAULT NULL,
  `role` enum('usager','admin') NOT NULL DEFAULT 'usager',
  `est_actif` tinyint(4) NOT NULL DEFAULT 1,
  `email` varchar(256) DEFAULT NULL,
  `password` varchar(256) DEFAULT NULL,
  `date_creation` timestamp NULL DEFAULT current_timestamp(),
  `last_login` date NOT NULL DEFAULT current_timestamp(),
  `controle_en_cours` int(11) NOT NULL DEFAULT 0,
  `facture_en_saisie` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `controle`
--

CREATE TABLE `controle` (
  `id` int(11) NOT NULL,
  `en_cours` tinyint(1) NOT NULL DEFAULT 1,
  `date_heure_controle` timestamp NULL DEFAULT current_timestamp(),
  `date_controle` date GENERATED ALWAYS AS (cast(`date_heure_controle` as date)) VIRTUAL,
  `utilisateur` varchar(30) NOT NULL,
  `utilisateur_id` int(11) DEFAULT NULL,
  `epi_controles` text DEFAULT NULL,
  `remarques` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categorie`
--
ALTER TABLE `categorie`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_UNIQUE` (`id`),
  ADD UNIQUE KEY `NOM_CATEGORIE_UNIQUE` (`libelle`);

--
-- Indexes for table `fabricant`
--
ALTER TABLE `fabricant`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_UNIQUE` (`id`),
  ADD UNIQUE KEY `NOM_FABRICANT_UNIQUE` (`libelle`);

--
-- Indexes for table `facture`
--
ALTER TABLE `facture`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_UNIQUE` (`id`);

--
-- Indexes for table `affectation`
--
ALTER TABLE `affectation`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_UNIQUE` (`id`),
  ADD UNIQUE KEY `NOM_affectation_UNIQUE` (`libelle`);

--
-- Indexes for table `matos`
--
ALTER TABLE `matos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ID_Materiel_UNIQUE` (`id`),
  ADD UNIQUE KEY `reference` (`reference`),
  ADD KEY `fk_matos_affectation` (`affectation_id`),
  ADD KEY `fk_matos_controle` (`controle_id`),
  ADD KEY `fk_matos_categorie` (`categorie_id`),
  ADD KEY `fk_matos_facture` (`facture_id`),
  ADD KEY `fk_matos_fabricant` (`fabricant_id`);

--
-- Indexes for table `utilisateur`
--
ALTER TABLE `utilisateur`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_UNIQUE` (`id`),
  ADD UNIQUE KEY `username_UNIQUE` (`username`);

--
-- Indexes for table `controle`
--
ALTER TABLE `controle`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_UNIQUE` (`id`),
  ADD KEY `fk_controle_utilisateur_idx` (`utilisateur_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categorie`
--
ALTER TABLE `categorie`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fabricant`
--
ALTER TABLE `fabricant`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `facture`
--
ALTER TABLE `facture`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `affectation`
--
ALTER TABLE `affectation`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `matos`
--
ALTER TABLE `matos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `utilisateur`
--
ALTER TABLE `utilisateur`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `controle`
--
ALTER TABLE `controle`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `fiche`
--
DROP TABLE IF EXISTS `fiche`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `fiche`  AS SELECT `m`.`id` AS `id`, `m`.`reference` AS `ref`, `m`.`libelle` AS `libelle`, `m`.`en_service` AS `en_service`, `cat`.`libelle` AS `categorie`, `cat`.`id` AS `categorie_id`, `f`.`libelle` AS `fabricant`, `f`.`id` AS `fabricant_id`, `a`.`libelle` AS `affectation`, `a`.`id` AS `affectation_id`, `fac`.`libelle` AS `facture`, `fac`.`id` AS `facture_id`, `fac`.`date_facture` AS `date_facture`, `m`.`nb_elements` AS `nb_elements`, `m`.`date_max` AS `date_max`, `m`.`date_debut` AS `date_debut`, `co`.`id` AS `controle_id`, `co`.`date_controle` AS `date_controle`, `m`.`remarques` AS `remarques`, `m`.`photo` AS `photo`, `m`.`nb_elements_initial` AS `nb_elements_initial`, `co`.`en_cours` AS `en_controle`, `fac`.`en_saisie` AS `facture_en_saisie` FROM (((((`matos` `m` join `categorie` `cat` on(`m`.`categorie_id` = `cat`.`id`)) join `fabricant` `f` on(`m`.`fabricant_id` = `f`.`id`)) join `affectation` `a` on(`m`.`affectation_id` = `a`.`id`)) join `facture` `fac` on(`m`.`facture_id` = `fac`.`id`)) join `controle` `co` on(`m`.`controle_id` = `co`.`id`)) WHERE `m`.`en_service` <> 0 ;

-- --------------------------------------------------------

--
-- Structure for view `liste`
--
DROP TABLE IF EXISTS `liste`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `liste`  AS SELECT `matos`.`id` AS `id`, `matos`.`reference` AS `ref`, `matos`.`libelle` AS `libelle`, `fabricant`.`libelle` AS `fabricant`, `categorie`.`libelle` AS `categorie`, `matos`.`categorie_id` AS `categorie_id`, `affectation`.`libelle` AS `affectation`, `matos`.`affectation_id` AS `affectation_id`, `matos`.`nb_elements` AS `nb_elements`, `controle`.`date_controle` AS `date_controle`, `matos`.`date_max` AS `date_max`, `matos`.`en_service` AS `en_service`, `matos`.`controle_id` AS `controle_id`, `facture`.`date_facture` AS `date_facture`, `facture`.`id` AS `facture_id` FROM (((((`matos` join `facture`) join `fabricant`) join `categorie`) join `affectation`) join `controle`) WHERE 0 <> `matos`.`en_service` AND `matos`.`categorie_id` = `categorie`.`id` AND `matos`.`fabricant_id` = `fabricant`.`id` AND `matos`.`controle_id` = `controle`.`id` AND `matos`.`affectation_id` = `affectation`.`id` AND `matos`.`facture_id` = `facture`.`id` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `matos`
--
ALTER TABLE `matos`
  ADD CONSTRAINT `fk_matos_categorie` FOREIGN KEY (`categorie_id`) REFERENCES `categorie` (`id`),
  ADD CONSTRAINT `fk_matos_fabricant` FOREIGN KEY (`fabricant_id`) REFERENCES `fabricant` (`id`),
  ADD CONSTRAINT `fk_matos_facture` FOREIGN KEY (`facture_id`) REFERENCES `facture` (`id`),
  ADD CONSTRAINT `fk_matos_affectation` FOREIGN KEY (`affectation_id`) REFERENCES `affectation` (`id`),
  ADD CONSTRAINT `fk_matos_controle` FOREIGN KEY (`controle_id`) REFERENCES `controle` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
