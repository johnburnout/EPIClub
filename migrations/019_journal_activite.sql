-- =============================================
-- 019_journal_activite.sql
-- Journal d'activité et alerte « EPI non vérifiés ».
--
--   - journal_activite : connexions, contrôles et prêts (consultation
--     et export texte : administrateurs ; réinitialisation : super
--     administrateurs). utilisateur : nom affiché au moment de l'action
--     (conservé si le compte est supprimé ; utilisateur_id passe à NULL).
--   - club.alerte_controle_actif : notification du tableau de bord pour
--     les EPI non vérifiés (dernier contrôle ou, à défaut, mise en
--     service) depuis plus de club.alerte_controle_mois mois (3 à 12,
--     6 par défaut). Désactivée par défaut.
--   - club_equipement.date_dernier_controle : n'était plus renseignée à
--     la clôture des contrôles ; rattrapage depuis les contrôles clôturés
--     (lignes vérifiées : OK, KO ou hors service).
-- =============================================

CREATE TABLE IF NOT EXISTS `journal_activite` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `date_action` DATETIME NOT NULL,
  `type` VARCHAR(16) NOT NULL,
  `utilisateur_id` INT(11) NULL DEFAULT NULL,
  `utilisateur` VARCHAR(100) NOT NULL DEFAULT '',
  `action` VARCHAR(255) NOT NULL,
  `ip` VARCHAR(45) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal_activite_date` (`date_action`),
  KEY `idx_journal_activite_type` (`type`, `date_action`),
  CONSTRAINT `fk_journal_activite_utilisateur` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `club`
    ADD COLUMN `alerte_controle_actif` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `alerte_controle_mois` TINYINT UNSIGNED NOT NULL DEFAULT 6;

UPDATE `club_equipement` ce
  JOIN (
        SELECT cl.`equipement_id`, MAX(DATE(COALESCE(cl.`date_controle`, c.`date_fin`))) AS `derniere`
          FROM `controle_ligne` cl
          JOIN `controle` c ON c.`id` = cl.`controle_id`
         WHERE c.`statut` = 'cloture'
           AND cl.`statut` IN ('controle_ok', 'controle_ko', 'hors_service')
         GROUP BY cl.`equipement_id`
       ) x ON x.`equipement_id` = ce.`id`
   SET ce.`date_dernier_controle` = x.`derniere`
 WHERE x.`derniere` IS NOT NULL
   AND (ce.`date_dernier_controle` IS NULL OR ce.`date_dernier_controle` < x.`derniere`);
