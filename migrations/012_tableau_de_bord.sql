-- =============================================
-- 012_tableau_de_bord.sql
-- Personnalisation du tableau de bord (page d'accueil).
--
--   - club.logo : logo du club (fichier réencodé dans public/images/club/).
--     NULL → logo EPIClub par défaut.
--   - club.image_fond : photo de fond du tableau de bord (même dossier).
--     NULL → image par défaut (assets/img/screen.jpg).
--   - club.alerte_renouvellement_mois : horizon (en mois) de la
--     notification « EPI à renouveler » du tableau de bord. Défaut 3.
-- =============================================

ALTER TABLE `club`
    ADD COLUMN `logo` VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN `image_fond` VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN `alerte_renouvellement_mois` TINYINT UNSIGNED NOT NULL DEFAULT 3;
