-- =========================================================
-- Save Money (wallet <-> savings, daily interest, goals)
-- Run ONCE on an existing database (phpMyAdmin > SQL tab).
-- Fresh installs get this from database/schema.sql already.
-- =========================================================

ALTER TABLE `rbnw_savings_goals`
  ADD COLUMN `is_main` TINYINT(1) NOT NULL DEFAULT 0 AFTER `plan_id`,
  ADD COLUMN `interest_earned` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `current_balance`,
  ADD COLUMN `last_accrual_date` DATE DEFAULT NULL AFTER `interest_earned`,
  ADD KEY `idx_goal_user_status` (`user_id`, `status`);

ALTER TABLE `rbnw_savings_transactions`
  ADD COLUMN `reference_no` VARCHAR(40) DEFAULT NULL AFTER `goal_id`,
  ADD COLUMN `tax_withheld` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `amount`,
  ADD COLUMN `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `tax_withheld`,
  ADD KEY `idx_savtx_goal_created` (`goal_id`, `created_at`);
