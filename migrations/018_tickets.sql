-- =============================================
-- 018_tickets.sql
-- Demandes de modification d'un équipement (tickets).
--
-- Un contrôleur ou un administrateur demande, depuis la fiche d'un
-- équipement, des modifications que son rôle ne lui permet pas de faire
-- (une remarque par modification). Une demande crée un ticket par niveau
-- de rôle requis (ROLE_ADMIN, ROLE_SUPER_ADMIN), traité — fait ou refusé —
-- par une personne de ce niveau ou plus.
--
--   - ticket : statut ouvert → fait | refuse ; reponse (motif de refus
--     obligatoire) ; vu_par_demandeur : la notification de clôture reste
--     sur le tableau de bord du demandeur jusqu'à ce qu'il la consulte.
--   - ticket_modification : modifications demandées (type, remarque).
--   - utilisateur.ticket_mail_envoye_le : date du dernier mail de ticket ;
--     pas de nouveau mail tant que la personne ne s'est pas reconnectée
--     (last_activity) depuis.
-- =============================================

CREATE TABLE IF NOT EXISTS `ticket` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `equipement_id` INT(11) NOT NULL,
  `demandeur_id` INT(11) NOT NULL,
  `role_requis` VARCHAR(32) NOT NULL,
  `statut` ENUM('ouvert','fait','refuse') NOT NULL DEFAULT 'ouvert',
  `date_creation` DATETIME NOT NULL,
  `traite_par` INT(11) NULL DEFAULT NULL,
  `date_traitement` DATETIME NULL DEFAULT NULL,
  `reponse` TEXT NULL,
  `vu_par_demandeur` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_statut_role` (`statut`, `role_requis`),
  KEY `idx_ticket_demandeur` (`demandeur_id`, `statut`),
  KEY `idx_ticket_equipement` (`equipement_id`),
  CONSTRAINT `fk_ticket_equipement` FOREIGN KEY (`equipement_id`) REFERENCES `club_equipement` (`id`),
  CONSTRAINT `fk_ticket_demandeur` FOREIGN KEY (`demandeur_id`) REFERENCES `utilisateur` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_traite_par` FOREIGN KEY (`traite_par`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ticket_modification` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `remarque` TEXT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_modification_ticket` (`ticket_id`),
  CONSTRAINT `fk_ticket_modification_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ticket` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `utilisateur`
    ADD COLUMN `ticket_mail_envoye_le` DATETIME NULL DEFAULT NULL;
