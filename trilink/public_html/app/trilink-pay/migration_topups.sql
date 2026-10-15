-- ============================================================
-- TRILINK – Production top-up migration (QR Ph + Teller)
-- Run once in phpMyAdmin on u718203951_trilinkdb.
-- Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `tl_topups` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `topup_code` varchar(30) NOT NULL,
  `account_type` enum('passenger','driver') NOT NULL,
  `account_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `channel` enum('qrph','teller') NOT NULL,
  `status` enum('pending','paid','expired','cancelled','failed') NOT NULL DEFAULT 'pending',
  `provider` varchar(20) DEFAULT NULL,
  `provider_intent_id` varchar(64) DEFAULT NULL,
  `provider_payment_id` varchar(64) DEFAULT NULL,
  `qr_image` mediumtext DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `load_center_id` int(10) UNSIGNED DEFAULT NULL,
  `credited_txn_code` varchar(30) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_topup_code` (`topup_code`),
  UNIQUE KEY `uq_provider_intent` (`provider_intent_id`),
  KEY `idx_owner` (`account_type`,`account_id`,`status`),
  KEY `idx_status` (`status`),
  KEY `idx_load_center` (`load_center_id`,`status`,`paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bring over the old driver Load Center requests so history and
-- load-center totals stay intact.
INSERT IGNORE INTO `tl_topups`
  (`topup_code`,`account_type`,`account_id`,`amount`,`channel`,`status`,`load_center_id`,`paid_at`,`created_at`,`expires_at`)
SELECT `request_code`, 'driver', `driver_id`, `amount`, 'teller',
       CASE `status` WHEN 'completed' THEN 'paid' WHEN 'cancelled' THEN 'cancelled' ELSE 'pending' END,
       `load_center_id`, `confirmed_at`, `created_at`,
       CASE `status` WHEN 'pending' THEN NOW() + INTERVAL 24 HOUR ELSE NULL END
FROM `tl_driver_topup_requests`;

-- New settings
INSERT IGNORE INTO `tl_settings` (`key`,`value`,`description`) VALUES
('topup_teller_expiry_hours', '24', 'Hours a teller (cash) top-up code stays valid'),
('topup_max_pending',         '3',  'Max open top-up requests per wallet');
