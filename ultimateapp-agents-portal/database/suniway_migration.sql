-- UBills (Bills Pay), ULoad (E-Load) and UCash In (e-wallet cash-in) through the SUNIWAY Partner API.
-- Import ONCE after backing up the database. Then enter the Partner API key in Admin > Bills & Load (SUNIWAY) > Settings.

CREATE TABLE IF NOT EXISTS `suniway_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` varchar(40) NOT NULL,
  `request_key` char(64) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `service` enum('bills_pay','eload','ecash') NOT NULL,
  `provider_id` varchar(120) NOT NULL,
  `provider_name` varchar(160) NOT NULL,
  `provider_type` varchar(60) NOT NULL,
  `account_number` varchar(160) NOT NULL,
  `amount_centavos` bigint(20) NOT NULL,
  `service_fee_centavos` bigint(20) NOT NULL DEFAULT 0,
  `provider_fee_centavos` bigint(20) NOT NULL DEFAULT 0,
  `app_fee_centavos` bigint(20) NOT NULL DEFAULT 0,
  `total_centavos` bigint(20) NOT NULL,
  `status` enum('submitting','pending','success','failed','unknown') NOT NULL DEFAULT 'submitting',
  `remote_id` varchar(120) DEFAULT NULL,
  `remote_reference` varchar(120) DEFAULT NULL,
  `remote_status` varchar(40) DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `response_json` mediumtext DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_suniway_ref` (`reference`),
  UNIQUE KEY `uniq_suniway_request` (`user_id`, `request_key`),
  KEY `idx_suniway_user` (`user_id`, `created_at`),
  KEY `idx_suniway_status` (`status`),
  KEY `idx_suniway_remote` (`remote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `is_secret`) VALUES
('suniway.enabled', '0', 0),
('suniway.show_tiles', '1', 0),
('suniway.base_url', 'https://api-sunikiosk.suniway.ph/api/partner-api', 0),
('suniway.payment_method', 'CASH', 0),
('suniway.fee_bills_pay_centavos', '0', 0),
('suniway.fee_eload_centavos', '0', 0),
('suniway.fee_ecash_centavos', '0', 0);
