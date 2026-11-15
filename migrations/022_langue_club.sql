-- =============================================
-- 022_langue_club.sql
-- Langue de l'interface du club.
--
--   - club.langue : fr, en, es ou it ; NULL = automatique (langue du
--     navigateur de chaque personne, puis le français). Réglée par un
--     administrateur dans la personnalisation du tableau de bord ;
--     pré-remplie par l'installeur si une langue y a été choisie.
-- =============================================

ALTER TABLE `club`
    ADD COLUMN `langue` VARCHAR(5) NULL DEFAULT NULL;
