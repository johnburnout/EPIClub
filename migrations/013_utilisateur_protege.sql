-- =============================================
-- 013_utilisateur_protege.sql
-- Compte super-administrateur protégé (décision 2026-10-20).
--
--   - utilisateur.est_protege : compte créé par le setup. Il ne peut
--     être ni supprimé, ni rétrogradé, et seul son titulaire peut
--     modifier son email ou son mot de passe.
--   - Installations existantes : le premier SUPER_ADMIN (id minimal)
--     devient le compte protégé.
-- =============================================

ALTER TABLE `utilisateur`
    ADD COLUMN `est_protege` TINYINT(1) NOT NULL DEFAULT 0;

UPDATE `utilisateur`
SET `est_protege` = 1
WHERE `id` = (
    SELECT min_id FROM (
        SELECT MIN(`id`) AS min_id FROM `utilisateur` WHERE `role` = 'ROLE_SUPER_ADMIN'
    ) AS t
);
