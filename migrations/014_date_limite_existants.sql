-- =============================================
-- 014_date_limite_existants.sql
-- Rattrapage de la date limite d'utilisation (v1.2.0).
--
-- Depuis la v1.2.0, la validation d'une acquisition donne aux
-- équipements à « durée maximum d'utilisation » une date limite égale à
-- la date de facture + 10 ans, au dernier jour du mois
-- (Epiclub\Domain\DureeDeVie). Cette migration applique la même règle
-- aux équipements EXISTANTS qui n'ont pas encore de date limite :
--   - case « durée max » cochée (club_equipement.duree_max_utilisation) ;
--   - date_fin_utilisation vide ;
--   - équipement non supprimé, rattaché à une acquisition datée.
-- Une date limite déjà renseignée n'est jamais modifiée.
-- Idempotente : une seconde exécution ne trouve plus rien à compléter.
-- =============================================

UPDATE `club_equipement` e
INNER JOIN `acquisition` a ON a.`id` = e.`acquisition_id`
SET e.`date_fin_utilisation` = LAST_DAY(
        DATE_ADD(DATE_FORMAT(a.`facture_date`, '%Y-%m-01'), INTERVAL 10 YEAR)
    )
WHERE e.`duree_max_utilisation` = 1
  AND e.`date_fin_utilisation` IS NULL
  AND e.`deleted_at` IS NULL
  AND a.`facture_date` IS NOT NULL;
