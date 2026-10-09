-- KYC (identity verification) for customers, merchants, URide drivers and agents:
-- valid ID front and back + selfie liveness (look left, right, up, down, blink).
-- Import ONCE. Back up the database first.

CREATE TABLE IF NOT EXISTS `kyc_submissions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` varchar(24) NOT NULL,
  `subject_type` enum('user','merchant','driver','agent') NOT NULL,
  `subject_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `birthdate` date DEFAULT NULL,
  `id_type` varchar(40) NOT NULL,
  `id_number` varchar(60) DEFAULT NULL,
  `id_front` varchar(80) NOT NULL,
  `id_back` varchar(80) NOT NULL,
  `selfie` varchar(80) NOT NULL,
  `liveness_frames` text NOT NULL,
  `liveness_log` text DEFAULT NULL,
  `liveness_mode` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `status` enum('pending','approved','rejected','needs_info') NOT NULL DEFAULT 'pending',
  `review_note` varchar(255) DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_kyc_ref` (`reference`),
  KEY `idx_kyc_subject` (`subject_type`, `subject_id`),
  KEY `idx_kyc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rules (Admin > KYC verification > Settings). 1 = on.
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `is_secret`) VALUES
('kyc.required_merchant', '1', 0),
('kyc.required_driver', '1', 0),
('kyc.required_agent', '1', 0),
('kyc.required_user', '0', 0);
