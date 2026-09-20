-- =============================================
-- 002_super_admin.sql
-- Ajoute le rôle SUPER_ADMIN dans l'écosystème.
--
-- Note : la colonne `utilisateur.role` est un varchar(32), aucune
-- altération de schéma n'est nécessaire pour accueillir la valeur
-- 'ROLE_SUPER_ADMIN' (17 caractères).
--
-- Deux étapes :
--   1. Promouvoir le compte historique 'admin' s'il existe et est ADMIN.
--   2. Sinon (ou en complément), si aucun SUPER_ADMIN n'existe,
--      promouvoir le premier utilisateur (id minimal), qui correspond
--      au compte créé par le setup, quel que soit son username.
--
-- Idempotente : les clauses WHERE garantissent qu'une réexécution
-- ne produit pas d'effet indésirable.
-- =============================================

-- Étape 1 : promotion du compte historique 'admin'
UPDATE `utilisateur`
SET `role` = 'ROLE_SUPER_ADMIN'
WHERE `username` = 'admin'
  AND `role` = 'ROLE_ADMIN';

-- Étape 2 : fallback pour les installations dont le compte initial
-- a un username différent de 'admin'.
-- On cible l'utilisateur avec l'id le plus petit, uniquement si
-- aucun SUPER_ADMIN n'existe après l'étape 1.
UPDATE `utilisateur`
SET `role` = 'ROLE_SUPER_ADMIN'
WHERE `id` = (
    SELECT min_id FROM (
        SELECT MIN(`id`) AS min_id FROM `utilisateur`
    ) AS t1
)
AND NOT EXISTS (
    SELECT 1 FROM (
        SELECT 1 FROM `utilisateur` WHERE `role` = 'ROLE_SUPER_ADMIN'
    ) AS t2
);