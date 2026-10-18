-- =============================================
-- 006_references_auto.sql
-- Référencement automatique des équipements.
--
--   - categorie.code : code court utilisé dans les références
--     (ex. COR pour « Cordes »). NULL à l'ajout : les codes sont
--     attribués à la première utilisation / édition (self-healing
--     dans ReferenceGenerator) — pas de backfill SQL, la règle de
--     collision (3 lettres → 2 lettres + n°) est applicative.
--     UNIQUE accepte plusieurs NULL (comportement MySQL standard).
--
--   - club.ref_modele : modèle global de référence (singleton club,
--     id=1). Variables : YY (année 2 chiffres), YYYY (4 chiffres),
--     CAT (code catégorie), N… (n° d'ordre, autant de N que de
--     chiffres). Défaut 'YYCATNNN' → ex. 26COR001.
--
--   - acquisition_ligne.reference_forcee : référence d'équipement
--     explicitement fournie (import Excel/CSV). NULL → la validation
--     génère la référence selon le modèle. NB : acquisition_ligne
--     .reference (UNIQUE) reste la référence produit/fournisseur.
-- =============================================

ALTER TABLE `categorie`
    ADD COLUMN `code` VARCHAR(8) NULL DEFAULT NULL AFTER `libelle`,
    ADD UNIQUE KEY `code` (`code`);

ALTER TABLE `club`
    ADD COLUMN `ref_modele` VARCHAR(64) NOT NULL DEFAULT 'YYCATNNN';

ALTER TABLE `acquisition_ligne`
    ADD COLUMN `reference_forcee` VARCHAR(32) NULL DEFAULT NULL;
