-- =============================================
-- 017_egare_retards.sql
-- Suppression des équipements égarés après un délai.
--
--   - club_equipement.date_egare : date à laquelle l'équipement a été
--     déclaré égaré (clôture d'un contrôle ou vérification du retour
--     d'un prêt) ; remise à NULL quand il quitte ce statut.
--   - club.delai_suppression_egare_mois : délai (mois) au-delà duquel un
--     équipement égaré peut être supprimé (page Club). Défaut 12.
--
-- Équipements déjà égarés (statut 4) : le délai démarre à la migration.
-- =============================================

ALTER TABLE `club_equipement`
    ADD COLUMN `date_egare` DATE NULL DEFAULT NULL;

ALTER TABLE `club`
    ADD COLUMN `delai_suppression_egare_mois` SMALLINT UNSIGNED NOT NULL DEFAULT 12;

UPDATE `club_equipement` SET `date_egare` = CURDATE() WHERE `statut` = 4 AND `date_egare` IS NULL;
