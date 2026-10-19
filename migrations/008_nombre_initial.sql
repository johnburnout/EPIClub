-- Migration 008 : nombre initial des lots (décision produit 2026-10-19)
--
-- Un équipement issu d'un lot conserve le NOMBRE INITIAL d'éléments du
-- lot : le nombre actuel (club_equipement.nombre) reste modifiable dans
-- la fiche équipement mais ne peut pas dépasser ce nombre initial.

ALTER TABLE club_equipement
    ADD COLUMN nombre_initial INT NOT NULL DEFAULT 1 AFTER nombre;

-- Les lots existants : le nombre initial est le nombre actuel.
UPDATE club_equipement SET nombre_initial = nombre WHERE nombre > 1;
