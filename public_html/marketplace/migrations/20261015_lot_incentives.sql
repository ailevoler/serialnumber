-- Lot completion incentives ("Choose Your Plan and Get Your Bonus").
-- Import after 20261015_lot_referral_bonus.sql.
-- cash_lot_plans.incentive_minor / incentive_invites: incentive in USD cents paid when a purchased Lot completes,
--   and the number of invited Traders (registered via the buyer's link and bought a Lot before the buyer's Lot ends) required.
-- cash_lots: snapshot of those terms at purchase plus the one-time settlement result.
-- Lots purchased before this update keep 0 incentive (no retroactive credits).
-- The app also adds these columns automatically if this file is not imported.

ALTER TABLE cash_lot_plans
  ADD COLUMN IF NOT EXISTS incentive_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS incentive_invites INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE cash_lots
  ADD COLUMN IF NOT EXISTS incentive_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS incentive_invites INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS incentive_status VARCHAR(12) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS incentive_invites_counted INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS incentive_request_id BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS incentive_settled_at DATETIME NULL DEFAULT NULL;

-- Client promo: 60 days $60 + invite 3 · 75 days $200 + invite 8 · 90 days $480, no invites.
UPDATE cash_lot_plans SET incentive_minor=6000,  incentive_invites=3 WHERE incentive_minor=0 AND (name='Lot 4' OR (price_minor=48000  AND name NOT IN ('Lot 1','Lot 2','Lot 3','Lot 5','Lot 6')));
UPDATE cash_lot_plans SET incentive_minor=20000, incentive_invites=8 WHERE incentive_minor=0 AND (name='Lot 5' OR (price_minor=100000 AND name NOT IN ('Lot 1','Lot 2','Lot 3','Lot 4','Lot 6')));
UPDATE cash_lot_plans SET incentive_minor=48000, incentive_invites=0 WHERE incentive_minor=0 AND (name='Lot 6' OR (price_minor=180000 AND name NOT IN ('Lot 1','Lot 2','Lot 3','Lot 4','Lot 5')));
