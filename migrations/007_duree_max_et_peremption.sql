-- =============================================
-- 007_duree_max_et_peremption.sql
-- Durée maximum d'utilisation + péremption à l'import.
--
--   - categorie.duree_max_utilisation : valeur par défaut du
--     booléen « durée maximum d'utilisation » pour les équipements
--     de la catégorie (matériel à durée de vie limitée par le
--     fabricant, généralement 10 ans).
--
--   - club_equipement.duree_max_utilisation : hérité de la catégorie
--     à la génération (validation d'acquisition / import),
--     modifiable ensuite dans la fiche équipement.
--
--   - acquisition_ligne.date_peremption : date de péremption issue
--     de l'import Excel/CSV (année seule → 31/12). Reportée sur
--     club_equipement.date_fin_utilisation à la génération.
-- =============================================

ALTER TABLE `categorie`
    ADD COLUMN `duree_max_utilisation` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `club_equipement`
    ADD COLUMN `duree_max_utilisation` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `acquisition_ligne`
    ADD COLUMN `date_peremption` DATE NULL DEFAULT NULL;
