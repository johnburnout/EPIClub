-- =============================================
-- 020_double_authentification.sql
-- Double authentification facultative (tous les rôles) : application
-- d'authentification (TOTP) et passkeys (WebAuthn).
--
--   - utilisateur.totp_secret : secret TOTP CHIFFRÉ (EncryptionService,
--     AES-256-GCM) ; NULL tant que l'application n'est pas activée.
--   - utilisateur.totp_active_le : date d'activation (NULL = inactive).
--   - utilisateur.totp_dernier_pas : dernier pas de temps (30 s) accepté,
--     pour refuser le rejeu d'un code déjà utilisé.
--   - utilisateur.codes_secours : codes de secours à usage unique (liste
--     JSON de hachages password_hash), générés avec le premier facteur.
--   - utilisateur_passkey : passkeys enregistrées (identifiant base64url,
--     clé publique PEM, compteur de signatures). Supprimées avec le
--     compte.
-- =============================================

ALTER TABLE `utilisateur`
    ADD COLUMN `totp_secret` VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN `totp_active_le` DATETIME NULL DEFAULT NULL,
    ADD COLUMN `totp_dernier_pas` BIGINT NULL DEFAULT NULL,
    ADD COLUMN `codes_secours` TEXT NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `utilisateur_passkey` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `utilisateur_id` INT(11) NOT NULL,
  `credential_id` VARCHAR(1400) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `cle_publique` TEXT NOT NULL,
  `compteur` INT UNSIGNED NOT NULL DEFAULT 0,
  `nom` VARCHAR(64) NOT NULL,
  `date_creation` DATETIME NOT NULL,
  `derniere_utilisation` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_passkey_credential` (`credential_id`),
  KEY `idx_passkey_utilisateur` (`utilisateur_id`),
  CONSTRAINT `fk_passkey_utilisateur` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateur` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
