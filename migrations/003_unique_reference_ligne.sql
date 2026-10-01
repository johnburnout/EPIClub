-- =============================================
-- 003_unique_reference_ligne.sql
-- Ajoute une contrainte d'unicité sur acquisition_ligne.reference.
--
-- Contexte : la référence d'une ligne d'acquisition est utilisée
-- comme base pour générer les références d'équipements (suffixées
-- par un numéro d'ordre). Deux lignes ne peuvent donc pas partager
-- la même référence.
--
-- Contrôle préalable (à exécuter avant migration) :
--   SELECT reference, COUNT(*) AS c
--   FROM acquisition_ligne
--   GROUP BY reference
--   HAVING c > 1;
-- Si des lignes remontent : les dédupliquer AVANT.
--
-- Note : MigrationManager ne rejoue pas une migration déjà appliquée
-- (suivi via schema_migrations), donc pas besoin d'idempotence.
-- =============================================

ALTER TABLE `acquisition_ligne`
    ADD UNIQUE KEY `reference` (`reference`);