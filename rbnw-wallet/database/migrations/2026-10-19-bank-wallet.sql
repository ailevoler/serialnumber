-- =========================================================
-- Bank Wallet (linked accounts + transfers to bank)
-- Run ONCE on an existing database (phpMyAdmin > SQL tab).
-- Fresh installs get this from database/schema.sql already.
-- =========================================================

ALTER TABLE `rbnw_bank_accounts`
  ADD COLUMN `account_number` VARCHAR(30) NOT NULL DEFAULT '' AFTER `bank_code`,
  ADD KEY `idx_bank_user` (`user_id`, `is_linked`);

CREATE TABLE IF NOT EXISTS `rbnw_bank_transfers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(40) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `bank_account_id` INT UNSIGNED DEFAULT NULL,
  `bank_name` VARCHAR(100) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `account_number_masked` VARCHAR(30) NOT NULL,
  `channel` ENUM('internal','instapay','pesonet') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
  `failure_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_bt_ref` (`reference_no`),
  KEY `idx_bt_user` (`user_id`, `created_at`),
  KEY `idx_bt_status` (`status`),
  CONSTRAINT `fk_bt_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `rbnw_wallet_transactions`
  MODIFY `type` ENUM('send','receive','cash_in','cash_out','top_up','bill_payment','loan_disbursement','loan_repayment',
    'savings_deposit','savings_withdrawal','savings_interest','donation','remittance_out','remittance_in',
    'bank_transfer','bank_transfer_refund') NOT NULL;
