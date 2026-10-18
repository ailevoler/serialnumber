-- =========================================================
-- RBNW WALLET DATABASE SCHEMA
-- Rural Bank of New Washington - Digital Wallet
-- Powered by SurgeTech / Surge Connect
-- Charset: utf8mb4 | Engine: InnoDB
-- =========================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ---------------------------------------------------------
-- USERS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `wallet_code` VARCHAR(20) NOT NULL COMMENT 'e.g. RBNW-000001',
  `mobile_number` VARCHAR(15) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `mpin_hash` VARCHAR(255) DEFAULT NULL COMMENT '6-digit MPIN, hashed',
  `password_hash` VARCHAR(255) DEFAULT NULL COMMENT 'used for web login fallback',
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `birthdate` DATE DEFAULT NULL,
  `civil_status` ENUM('Single','Married','Widowed','Separated') DEFAULT NULL,
  `home_address` VARCHAR(255) DEFAULT NULL,
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `wallet_level` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `kyc_status` ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
  `status` ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
  `failed_pin_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_mobile` (`mobile_number`),
  UNIQUE KEY `uq_wallet_code` (`wallet_code`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- WALLETS (1:1 with users, kept separate for future multi-currency/sub-wallets)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_wallets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'PHP',
  `status` ENUM('active','frozen') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_wallet` (`user_id`),
  CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- WALLET TRANSACTIONS (single ledger for all money movement)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_wallet_transactions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(40) NOT NULL,
  `wallet_id` INT UNSIGNED NOT NULL,
  `type` ENUM('send','receive','cash_in','cash_out','top_up','bill_payment','loan_disbursement','loan_repayment','savings_deposit','savings_withdrawal','savings_interest','donation','remittance_out','remittance_in') NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `balance_before` DECIMAL(14,2) NOT NULL,
  `balance_after` DECIMAL(14,2) NOT NULL,
  `counterparty_user_id` INT UNSIGNED DEFAULT NULL,
  `counterparty_name` VARCHAR(150) DEFAULT NULL,
  `counterparty_mobile` VARCHAR(15) DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending','success','failed','cancelled') NOT NULL DEFAULT 'success',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_reference` (`reference_no`),
  KEY `idx_wallet_created` (`wallet_id`,`created_at`),
  CONSTRAINT `fk_tx_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `rbnw_wallets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- CASH IN REQUESTS (PayMongo QR Ph — pending payment intents, before
-- they land as a 'cash_in' row in rbnw_wallet_transactions)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_cash_in_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `wallet_id` INT UNSIGNED NOT NULL,
  `reference_no` VARCHAR(40) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL COMMENT 'net amount credited to wallet (no fees)',
  `fee_paymongo` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `fee_rbnw` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_charged` DECIMAL(12,2) NOT NULL COMMENT 'amount + fees — what the payer actually pays via QR Ph',
  `paymongo_payment_intent_id` VARCHAR(80) DEFAULT NULL,
  `qr_image` MEDIUMTEXT DEFAULT NULL COMMENT 'base64 PNG data URI returned by PayMongo',
  `status` ENUM('pending','paid','expired','failed','cancelled') NOT NULL DEFAULT 'pending',
  `expires_at` DATETIME NOT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_cashin_ref` (`reference_no`),
  KEY `idx_cashin_pi` (`paymongo_payment_intent_id`),
  KEY `idx_cashin_user` (`user_id`,`status`),
  CONSTRAINT `fk_cashin_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cashin_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `rbnw_wallets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- CONTACTS (for Send Money "To Contacts" list)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_contacts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `contact_user_id` INT UNSIGNED DEFAULT NULL,
  `nickname` VARCHAR(100) DEFAULT NULL,
  `mobile_number` VARCHAR(15) NOT NULL,
  `is_frequent` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_contact_mobile` (`user_id`,`mobile_number`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_contact_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- LINKED BANK ACCOUNTS (Bank Wallet feature)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_bank_accounts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `bank_name` VARCHAR(100) NOT NULL,
  `bank_code` VARCHAR(20) NOT NULL,
  `account_number_masked` VARCHAR(30) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `is_linked` TINYINT(1) NOT NULL DEFAULT 1,
  `cached_balance` DECIMAL(14,2) DEFAULT NULL,
  `linked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_bank_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- BILLERS + BILL PAYMENTS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_billers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `category` VARCHAR(50) NOT NULL COMMENT 'Internet, Electricity, Water, Cable TV, Government, Transportation, Loans, Insurance',
  `logo` VARCHAR(255) DEFAULT NULL,
  `convenience_fee` DECIMAL(6,2) NOT NULL DEFAULT 10.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rbnw_bill_payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(40) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `biller_id` INT UNSIGNED NOT NULL,
  `account_number` VARCHAR(50) NOT NULL,
  `account_name` VARCHAR(150) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `convenience_fee` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL,
  `status` ENUM('success','failed','pending') NOT NULL DEFAULT 'success',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_bill_ref` (`reference_no`),
  CONSTRAINT `fk_bill_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bill_biller` FOREIGN KEY (`biller_id`) REFERENCES `rbnw_billers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- LOANS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_loans` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(40) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `term_months` TINYINT UNSIGNED NOT NULL,
  `monthly_rate` DECIMAL(5,2) NOT NULL,
  `monthly_amortization` DECIMAL(12,2) NOT NULL,
  `total_interest` DECIMAL(12,2) NOT NULL,
  `total_payable` DECIMAL(12,2) NOT NULL,
  `employment_type` VARCHAR(50) DEFAULT NULL,
  `company_name` VARCHAR(150) DEFAULT NULL,
  `monthly_income` DECIMAL(12,2) DEFAULT NULL,
  `reference_contact_name` VARCHAR(150) DEFAULT NULL,
  `reference_contact_number` VARCHAR(15) DEFAULT NULL,
  `status` ENUM('pending','approved','rejected','active','completed') NOT NULL DEFAULT 'pending',
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_loan_ref` (`reference_no`),
  CONSTRAINT `fk_loan_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- SAVINGS (Save Money feature)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_savings_plans` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `interest_rate` DECIMAL(5,2) NOT NULL COMMENT 'percent p.a.',
  `min_deposit` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  `lock_in_months` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `early_withdrawal_fee` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `description` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rbnw_savings_goals` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `goal_name` VARCHAR(150) NOT NULL,
  `target_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `target_date` DATE DEFAULT NULL,
  `current_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active','completed','closed') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_goal_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_goal_plan` FOREIGN KEY (`plan_id`) REFERENCES `rbnw_savings_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rbnw_savings_transactions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `goal_id` INT UNSIGNED NOT NULL,
  `type` ENUM('deposit','withdraw','interest') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_savtx_goal` FOREIGN KEY (`goal_id`) REFERENCES `rbnw_savings_goals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- REQUEST MONEY
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_money_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(40) NOT NULL,
  `requester_id` INT UNSIGNED NOT NULL,
  `requestee_id` INT UNSIGNED DEFAULT NULL,
  `requestee_mobile` VARCHAR(15) DEFAULT NULL,
  `requestee_name` VARCHAR(150) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `status` ENUM('pending','viewed','paid','cancelled','expired') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `viewed_at` DATETIME DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_req_ref` (`reference_no`),
  CONSTRAINT `fk_req_requester` FOREIGN KEY (`requester_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- DONATIONS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_donation_causes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `category` VARCHAR(50) DEFAULT NULL COMMENT 'Aklan, Kalibo, Boracay',
  `goal_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `raised_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `image` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rbnw_donations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(40) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `cause_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `donation_type` ENUM('one_time','monthly') NOT NULL DEFAULT 'one_time',
  `message` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_don_ref` (`reference_no`),
  CONSTRAINT `fk_don_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_don_cause` FOREIGN KEY (`cause_id`) REFERENCES `rbnw_donation_causes`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- QR CODES (Scan QR / receive / merchant payment)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_qr_codes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(40) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `type` ENUM('receive','merchant') NOT NULL DEFAULT 'receive',
  `amount` DECIMAL(12,2) DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `status` ENUM('active','used','expired','cancelled') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_qr_ref` (`reference_no`),
  CONSTRAINT `fk_qr_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- OTP + NOTIFICATIONS + SESSIONS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbnw_otp_codes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `mobile_number` VARCHAR(15) NOT NULL,
  `otp_code` VARCHAR(10) NOT NULL,
  `purpose` ENUM('register','login','reset_mpin','transaction') NOT NULL DEFAULT 'register',
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_mobile_purpose` (`mobile_number`,`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rbnw_notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `type` VARCHAR(30) DEFAULT 'general',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_notif_user` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `rbnw_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- SEED DATA
-- =========================================================

INSERT IGNORE INTO `rbnw_billers` (`id`,`name`,`category`,`convenience_fee`,`sort_order`) VALUES
(1,'PANTELCO - Panay Telephone Corporation','Internet',10.00,1),
(2,'KALIBO CABLE - Kalibo Cable Television Network Inc.','Cable TV',10.00,2),
(3,'CONVERGE','Internet',10.00,3),
(4,'PLDT Home','Internet',10.00,4),
(5,'Globe At Home','Internet',10.00,5),
(6,'Aklan Electric Cooperative','Electricity',10.00,6),
(7,'Boracay Waterworks Inc.','Water',10.00,7),
(8,'Caticlan Jetty Port','Transportation',10.00,8);

INSERT IGNORE INTO `rbnw_savings_plans` (`id`,`name`,`interest_rate`,`min_deposit`,`lock_in_months`,`early_withdrawal_fee`,`description`) VALUES
(1,'Flexi Save',0.50,50.00,0,0.00,'Withdraw anytime'),
(2,'Goal Saver',1.50,100.00,0,0.00,'Best for medium-term goals'),
(3,'Maxi Saver',2.50,1000.00,12,150.00,'12 months lock-in, highest rate');

INSERT IGNORE INTO `rbnw_donation_causes` (`id`,`name`,`description`,`category`,`goal_amount`,`raised_amount`,`is_active`) VALUES
(1,'Aklan Relief Fund','Help families affected by natural calamities','Aklan',100000.00,72000.00,1),
(2,'Kalibo Education Support','Support scholarships for local students','Kalibo',100000.00,45000.00,1),
(3,'Boracay Environment Initiative','Protect our beaches and marine life','Boracay',100000.00,61000.00,1),
(4,'Aklan Animal Welfare','Support local animal rescue and care programs','Aklan',50000.00,19000.00,1),
(5,'Youth for a Better Kalibo','Empower youth through skills and community programs','Kalibo',50000.00,13500.00,1);
