-- =========================================================
-- Donations (anonymous option, faster per-cause lookups)
-- Run ONCE on an existing database (phpMyAdmin > SQL tab).
-- =========================================================

ALTER TABLE `rbnw_donations`
  ADD COLUMN `is_anonymous` TINYINT(1) NOT NULL DEFAULT 0 AFTER `donation_type`,
  ADD KEY `idx_don_cause_created` (`cause_id`, `created_at`),
  ADD KEY `idx_don_user_created` (`user_id`, `created_at`);
