-- =============================================
-- 015_textile_remplacable.sql
-- Élément textile remplaçable (ex. sangle de dégaine).
--
--   - categorie.textile_remplacable : valeur par défaut des équipements
--     générés depuis cette catégorie ;
--   - club_equipement.textile_remplacable : modifiable sur la fiche ;
--   - controle_ligne.textile_remplace : le contrôleur a constaté le
--     remplacement de l'élément textile d'un équipement en maintenance.
--     À la clôture (statut OK), la date limite d'utilisation repart :
--     date du contrôle + 10 ans, fin de mois.
-- =============================================

ALTER TABLE `categorie`
    ADD COLUMN `textile_remplacable` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `club_equipement`
    ADD COLUMN `textile_remplacable` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `controle_ligne`
    ADD COLUMN `textile_remplace` TINYINT(1) NOT NULL DEFAULT 0;
