-- =============================================
-- 005_foreign_keys.sql
-- Contraintes FOREIGN KEY sur acquisition et acquisition_ligne
-- (Dette 9 : intégrité référentielle non garantie au niveau SGBD).
--
-- Décisions :
--   - acquisition.fournisseur_id  -> RESTRICT  (ne jamais cascader la
--     suppression d'un fournisseur vers des données financières)
--   - acquisition.saisie_par      -> RESTRICT  (compte utilisateur)
--   - acquisition_ligne.acquisition_id -> CASCADE (les lignes n'ont pas
--     d'existence indépendante ; le contrôleur supprime déjà les lignes
--     avant l'acquisition — CASCADE est un filet de sécurité)
--   - acquisition_ligne.categorie_id   -> RESTRICT
--
-- CONTRÔLES PRÉALABLES (si des lignes remontent, les traiter AVANT
-- d'appliquer la migration — MySQL refusera l'ALTER sinon) :
--
--   Orphelins acquisition (bloquants : l'ALTER échouera) :
--     SELECT a.id FROM acquisition a LEFT JOIN fournisseur f
--       ON a.fournisseur_id = f.id WHERE f.id IS NULL;
--     SELECT a.id FROM acquisition a LEFT JOIN utilisateur u
--       ON a.saisie_par = u.id WHERE u.id IS NULL;
--
--   Orphelins acquisition_ligne (purges automatiquement ci-dessous —
--   ce sont des lignes inaccessibles depuis l'application) :
--     SELECT l.id FROM acquisition_ligne l LEFT JOIN acquisition a
--       ON l.acquisition_id = a.id WHERE a.id IS NULL;
--     SELECT l.id FROM acquisition_ligne l LEFT JOIN categorie c
--       ON l.categorie_id = c.id WHERE c.id IS NULL;
--
-- Note : MigrationManager ne rejoue pas une migration déjà appliquée
-- (suivi via schema_migrations), donc pas besoin d'idempotence.
-- =============================================

-- Nettoyage des lignes d'acquisition orphelines (inaccessibles
-- applicativement : findByAcquisition() joint sur acquisition_id).
DELETE l FROM acquisition_ligne l
LEFT JOIN acquisition a ON l.acquisition_id = a.id
WHERE a.id IS NULL;

DELETE l FROM acquisition_ligne l
LEFT JOIN categorie c ON l.categorie_id = c.id
WHERE c.id IS NULL;

ALTER TABLE `acquisition`
    ADD CONSTRAINT `fk_acquisition_fournisseur`
        FOREIGN KEY (`fournisseur_id`) REFERENCES `fournisseur` (`id`),
    ADD CONSTRAINT `fk_acquisition_saisie_par`
        FOREIGN KEY (`saisie_par`) REFERENCES `utilisateur` (`id`);

ALTER TABLE `acquisition_ligne`
    ADD CONSTRAINT `fk_acquisition_ligne_acquisition`
        FOREIGN KEY (`acquisition_id`) REFERENCES `acquisition` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_acquisition_ligne_categorie`
        FOREIGN KEY (`categorie_id`) REFERENCES `categorie` (`id`);
