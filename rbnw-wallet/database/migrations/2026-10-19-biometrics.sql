-- =========================================================
-- Biometric login (WebAuthn / passkeys: fingerprint, Face ID)
-- Run ONCE on an existing database (phpMyAdmin > SQL tab).
-- =========================================================

ALTER TABLE `rbnw_users`
  ADD COLUMN `webauthn_handle` CHAR(32) DEFAULT NULL COMMENT 'random per-user id given to authenticators' AFTER `password_hash`;

CREATE TABLE IF NOT EXISTS `rbnw_webauthn_credentials` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `credential_id` VARCHAR(255) NOT NULL COMMENT 'base64url',
  `public_key_pem` TEXT NOT NULL,
  `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `label` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_webauthn_cred` (`credential_id`),
  KEY `idx_webauthn_user` (`user_id`),
  CONSTRAINT `fk_webauthn_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
