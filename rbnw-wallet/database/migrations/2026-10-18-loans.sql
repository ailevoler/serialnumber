-- =========================================================
-- Loans module (GLoan-style instant cash loan)
-- Run ONCE on an existing database (phpMyAdmin > SQL tab).
-- Fresh installs get this from database/schema.sql already.
-- =========================================================

ALTER TABLE `rbnw_loans`
  ADD COLUMN `purpose` VARCHAR(60) DEFAULT NULL AFTER `term_months`,
  ADD COLUMN `processing_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total_payable`,
  ADD COLUMN `net_proceeds` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `processing_fee`,
  ADD COLUMN `effective_rate_annual` DECIMAL(6,2) DEFAULT NULL AFTER `net_proceeds`,
  ADD COLUMN `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `effective_rate_annual`,
  ADD COLUMN `disbursed_at` DATETIME DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN `completed_at` DATETIME DEFAULT NULL AFTER `disbursed_at`,
  ADD KEY `idx_loan_user_status` (`user_id`, `status`);

CREATE TABLE IF NOT EXISTS `rbnw_loan_schedule` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `loan_id` INT UNSIGNED NOT NULL,
  `installment_no` TINYINT UNSIGNED NOT NULL,
  `due_date` DATE NOT NULL,
  `principal` DECIMAL(12,2) NOT NULL,
  `interest` DECIMAL(12,2) NOT NULL,
  `amount_due` DECIMAL(12,2) NOT NULL,
  `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('due','paid') NOT NULL DEFAULT 'due',
  `paid_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_loan_installment` (`loan_id`, `installment_no`),
  KEY `idx_sched_due` (`due_date`, `status`),
  CONSTRAINT `fk_sched_loan` FOREIGN KEY (`loan_id`) REFERENCES `rbnw_loans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
