-- Migration 011 : stock actuel des lots importés (décision produit 2026-10-20)
--
-- L'import Excel/CSV peut fournir, pour un lot, un stock actuel
-- inférieur au stock max (nombre initial). acquisition_ligne.nombre
-- reste le stock max ; nombre_actuel (NULL = lot complet) est reporté
-- sur club_equipement.nombre à la génération des équipements.

ALTER TABLE acquisition_ligne
    ADD COLUMN nombre_actuel INT NULL DEFAULT NULL AFTER nombre;
