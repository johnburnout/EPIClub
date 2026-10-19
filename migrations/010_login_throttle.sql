-- =============================================
-- 010_login_throttle.sql
-- [SÉCURITÉ — audit 2026-10-19 S2] Limitation des tentatives de
-- connexion et des demandes de réinitialisation de mot de passe.
--
-- Une ligne par clé surveillée (identifiant, adresse IP…). La clé est
-- stockée HACHÉE (SHA-256) : la table ne contient ni identifiant ni
-- adresse IP en clair. Horodatages en secondes Unix (pas d'ambiguïté
-- de fuseau horaire). Table purgée automatiquement (lignes > 48 h).
-- =============================================

CREATE TABLE IF NOT EXISTS `login_throttle` (
  `cle` CHAR(64) NOT NULL,
  `echecs` INT NOT NULL DEFAULT 0,
  `dernier_echec` INT NOT NULL,
  `bloque_jusqu_a` INT NULL DEFAULT NULL,
  PRIMARY KEY (`cle`),
  KEY `idx_dernier_echec` (`dernier_echec`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
