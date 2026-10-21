-- =============================================
-- 016_pret_materiel.sql
-- Gestionnaire de prêt de matériel (module facultatif).
--
--   - club.pret_actif : active le module (page Club, super-admin).
--     Désactivé par défaut.
--   - controle_ligne.statut : nouvelle valeur « egare » (matériel
--     déclaré égaré lors d'un contrôle).
--   - pret : fiche de prêt (emprunteur, dates, signature, statut
--     saisie → en_cours → termine).
--   - pret_ligne : matériel prêté ; etat_retour renseigné à la
--     vérification du retour (disponible, maintenance, egare).
--
-- Statuts d'équipement (club_equipement.statut, TINYINT) ajoutés côté
-- application : 3 = Prêté, 4 = Égaré (cf. Epiclub\Enum\EquipementStatut).
-- =============================================

ALTER TABLE `club`
    ADD COLUMN `pret_actif` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `controle_ligne`
    MODIFY COLUMN `statut` ENUM('a_controler','controle_ok','controle_ko','hors_service','egare')
        NOT NULL DEFAULT 'a_controler';

CREATE TABLE IF NOT EXISTS `pret` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `emprunteur_nom` VARCHAR(64) NOT NULL,
  `emprunteur_prenom` VARCHAR(64) NOT NULL,
  `emprunteur_telephone` VARCHAR(20) NOT NULL DEFAULT '',
  `emprunteur_licence` VARCHAR(32) NOT NULL DEFAULT '',
  `date_pret` DATE NOT NULL,
  `date_retour_prevue` DATE NOT NULL,
  `date_retour` DATE NULL DEFAULT NULL,
  `statut` ENUM('saisie','en_cours','termine') NOT NULL DEFAULT 'saisie',
  `remarques` TEXT NULL,
  `signature` MEDIUMTEXT NULL,
  `date_signature` DATETIME NULL DEFAULT NULL,
  `cree_par` INT(11) NULL DEFAULT NULL,
  `valide_par` INT(11) NULL DEFAULT NULL,
  `termine_par` INT(11) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_validation` DATETIME NULL DEFAULT NULL,
  `date_fin` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pret_statut` (`statut`),
  CONSTRAINT `fk_pret_cree_par` FOREIGN KEY (`cree_par`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pret_valide_par` FOREIGN KEY (`valide_par`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pret_termine_par` FOREIGN KEY (`termine_par`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pret_ligne` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pret_id` INT(11) NOT NULL,
  `equipement_id` INT(11) NOT NULL,
  `etat_retour` ENUM('disponible','maintenance','egare') NULL DEFAULT NULL,
  `date_verification` DATETIME NULL DEFAULT NULL,
  `verifie_par` INT(11) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pret_equipement` (`pret_id`, `equipement_id`),
  KEY `idx_pret_ligne_equipement` (`equipement_id`),
  CONSTRAINT `fk_pret_ligne_pret` FOREIGN KEY (`pret_id`) REFERENCES `pret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pret_ligne_equipement` FOREIGN KEY (`equipement_id`) REFERENCES `club_equipement` (`id`),
  CONSTRAINT `fk_pret_ligne_verifie_par` FOREIGN KEY (`verifie_par`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
