-- =====================================================================
-- SurgeBox V5.24 - Nationlink Settlement Report import (PDF / Excel / CSV)
-- Run once in phpMyAdmin > SQL on the live database (safe to run again).
-- The import itself works without these tables; they keep the import history.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `sb_settlement_imports` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` varchar(30) NOT NULL DEFAULT 'nationlink',
  `organization_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'client picked on import (NULL = auto-detect from MemberID)',
  `report_type` varchar(20) DEFAULT NULL COMMENT 'e.g. DTQR',
  `report_date` date DEFAULT NULL,
  `file_name` varchar(190) NOT NULL,
  `file_format` varchar(20) NOT NULL COMMENT 'pdf / xlsx / csv / excel-html',
  `file_sha256` char(64) NOT NULL,
  `rows_total` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_imported` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'new transactions recorded',
  `rows_matched` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'already recorded (webhook / earlier import)',
  `rows_mismatch` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'recorded with a different amount - check manually',
  `rows_unmatched` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'no client / QR for the MemberID, or error',
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_discount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_net` decimal(14,2) NOT NULL DEFAULT 0.00,
  `imported_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stl_imp_date` (`report_date`),
  KEY `idx_stl_imp_org` (`organization_id`),
  KEY `idx_stl_imp_sha` (`file_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sb_settlement_import_rows` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id` int(10) UNSIGNED NOT NULL,
  `row_no` int(10) UNSIGNED NOT NULL,
  `member_id` varchar(20) DEFAULT NULL COMMENT 'Nationlink MemberID / QR code, e.g. A10103',
  `trace_no` varchar(120) NOT NULL COMMENT 'Nationlink TRACE NO. (stored in the ledger as NL-<trace>)',
  `seq_no` varchar(20) DEFAULT NULL,
  `payer_name` varchar(190) DEFAULT NULL,
  `txn_time` datetime DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `discount` decimal(14,2) DEFAULT NULL,
  `net_settlement` decimal(14,2) DEFAULT NULL,
  `organization_id` int(10) UNSIGNED DEFAULT NULL,
  `transaction_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'sb_transactions.id created or matched',
  `status` enum('Imported','Matched','Mismatch','Unmatched','Duplicate','Error') NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stl_row_import` (`import_id`),
  KEY `idx_stl_row_trace` (`trace_no`),
  KEY `idx_stl_row_tx` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
