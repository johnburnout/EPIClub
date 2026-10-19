-- =============================================
-- 009_unique_reference_equipement.sql
-- [SÉCURITÉ — audit C5] Ajoute une contrainte d'unicité sur
-- club_equipement.reference.
--
-- Contexte : la référence est l'identifiant métier d'un équipement.
-- Avant cette migration, deux acquisitions importées avec la même
-- reference_forcee créaient deux équipements portant la même
-- référence, sans aucune erreur (doublons, rapports faux).
--
-- Contrôle préalable (à exécuter AVANT la migration) :
--   SELECT reference, COUNT(*) AS c
--   FROM club_equipement
--   WHERE deleted_at IS NULL
--   GROUP BY reference
--   HAVING c > 1;
-- Si des lignes remontent : les dédupliquer AVANT d'appliquer.
-- =============================================

ALTER TABLE `club_equipement`
    ADD UNIQUE KEY `reference` (`reference`);
