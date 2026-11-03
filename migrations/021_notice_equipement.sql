-- =============================================
-- 021_notice_equipement.sql
-- Lien vers la notice du fabricant sur la fiche équipement.
--
--   - club_equipement.notice_url : URL http(s) de la notice (PDF ou page
--     du fabricant) ; NULL si non renseignée. 2048 caractères : longueur
--     d'URL usuelle maximale. Recopiée lors d'une dissociation de lot,
--     modifiable sur la fiche et en modification groupée.
-- =============================================

ALTER TABLE `club_equipement`
    ADD COLUMN `notice_url` VARCHAR(2048) NULL DEFAULT NULL;
