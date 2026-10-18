-- =========================================================
-- KYC (valid ID front/back + selfie liveness)
-- Run ONCE on an existing database (phpMyAdmin > SQL tab).
-- =========================================================

CREATE TABLE IF NOT EXISTS `rbnw_kyc_submissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `id_type` VARCHAR(60) NOT NULL,
  `id_number` VARCHAR(60) NOT NULL,
  `id_front_path` VARCHAR(255) NOT NULL COMMENT 'relative to storage/kyc, outside public access',
  `id_back_path` VARCHAR(255) DEFAULT NULL,
  `selfie_path` VARCHAR(255) NOT NULL,
  `liveness` TEXT DEFAULT NULL COMMENT 'JSON: challenges passed + timings from the device',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `review_note` VARCHAR(255) DEFAULT NULL,
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME DEFAULT NULL,
  KEY `idx_kyc_user` (`user_id`, `submitted_at`),
  KEY `idx_kyc_status` (`status`),
  CONSTRAINT `fk_kyc_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
