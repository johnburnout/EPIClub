-- =============================================
-- 022_langue_utilisateur.sql
-- Langue d'affichage préférée de chaque utilisateur.
--
--   - utilisateur.langue : fr, en, es ou it ; NULL tant que l'utilisateur
--     n'a rien choisi (la langue du navigateur s'applique alors, puis le
--     français). Écrite par le sélecteur de langue du bandeau (POST /langue).
-- =============================================

ALTER TABLE `utilisateur`
    ADD COLUMN `langue` VARCHAR(5) NULL DEFAULT NULL;
