-- Agents Portal: referral links / codes and agent commissions on URide, UPass, UGo, ULocal and UEat.
-- Import ONCE after backing up the database (phpMyAdmin > Import, or: mysql dbname < agents_migration.sql).
-- Safe to import on the current production schema (2026-10). Do not import twice: the ALTER TABLE lines will fail.

CREATE TABLE IF NOT EXISTS `agents` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(16) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `barangay` varchar(40) DEFAULT NULL,
  `status` enum('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
  `status_note` varchar(255) DEFAULT NULL,
  `balance_centavos` bigint(20) NOT NULL DEFAULT 0,
  `payout_method` varchar(40) DEFAULT NULL,
  `payout_account_name` varchar(120) DEFAULT NULL,
  `payout_account_no` varchar(40) DEFAULT NULL,
  `link_clicks` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agent_code` (`code`),
  UNIQUE KEY `uniq_agent_email` (`email`),
  KEY `idx_agent_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which agent brought in each customer (set once, at sign-up).
ALTER TABLE `users`
  ADD COLUMN `referred_by_agent_id` int(10) UNSIGNED DEFAULT NULL,
  ADD COLUMN `referred_at` timestamp NULL DEFAULT NULL,
  ADD KEY `idx_users_agent` (`referred_by_agent_id`);

-- One row per paid activity of a referred customer. UNIQUE(source_type, source_id) = never paid twice.
CREATE TABLE IF NOT EXISTS `agent_commissions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `service_code` enum('URide','UPass','UGo','ULocal','UEat') NOT NULL,
  `source_type` enum('uride','service_request','ueat') NOT NULL,
  `source_id` bigint(20) UNSIGNED NOT NULL,
  `source_reference` varchar(40) NOT NULL,
  `base_centavos` bigint(20) NOT NULL,
  `rate_bp` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `fixed_centavos` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `amount_centavos` bigint(20) NOT NULL,
  `status` enum('pending','approved','reversed') NOT NULL DEFAULT 'pending',
  `note` varchar(190) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_commission_source` (`source_type`, `source_id`),
  KEY `idx_commission_agent` (`agent_id`, `status`),
  KEY `idx_commission_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agent_payouts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` varchar(40) NOT NULL,
  `agent_id` int(10) UNSIGNED NOT NULL,
  `amount_centavos` bigint(20) NOT NULL,
  `payout_method` varchar(40) NOT NULL,
  `payout_account_name` varchar(120) NOT NULL,
  `payout_account_no` varchar(40) NOT NULL,
  `status` enum('requested','paid','rejected') NOT NULL DEFAULT 'requested',
  `bank_reference` varchar(80) DEFAULT NULL,
  `note` varchar(300) DEFAULT NULL,
  `processed_by` int(10) UNSIGNED DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agent_payout_ref` (`reference`),
  KEY `idx_agent_payout` (`agent_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UPass / UGo / ULocal requests can now be marked completed by Admin (this approves the agent commission).
ALTER TABLE `service_requests`
  MODIFY `status` enum('pending','completed','cancelled','closed') NOT NULL DEFAULT 'pending';

-- Default commission rates (editable in Admin > Agents > Commission rates). 100 bp = 1%.
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `is_secret`) VALUES
('agents.enabled', '1', 0),
('agents.uride.percent_bp', '200', 0),
('agents.uride.fixed_centavos', '0', 0),
('agents.upass.percent_bp', '500', 0),
('agents.upass.fixed_centavos', '0', 0),
('agents.ugo.percent_bp', '500', 0),
('agents.ugo.fixed_centavos', '0', 0),
('agents.ulocal.percent_bp', '500', 0),
('agents.ulocal.fixed_centavos', '0', 0),
('agents.ueat.percent_bp', '300', 0),
('agents.ueat.fixed_centavos', '0', 0),
('agents.earn_days', '365', 0),
('agents.cookie_days', '30', 0),
('agents.hold_days', '3', 0),
('agents.payout_min_centavos', '50000', 0);
