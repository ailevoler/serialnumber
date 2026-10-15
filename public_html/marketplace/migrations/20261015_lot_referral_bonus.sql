-- Per-Lot referral bonus percentage.
-- Import after 20260923_lot_timeframe_hourly_earnings.sql.
-- cash_lot_plans.referral_bps: referral % for this package in basis points (500 = 5.00%); NULL uses the global referral setting.
-- cash_lots.referral_bps: snapshot taken at purchase so later package edits never change a bought Lot's bonus.
-- The app also adds these columns automatically if this file is not imported.

ALTER TABLE cash_lot_plans ADD COLUMN IF NOT EXISTS referral_bps INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE cash_lots ADD COLUMN IF NOT EXISTS referral_bps INT UNSIGNED NULL DEFAULT NULL;

-- Carry over the former hardcoded package percentages: $40=5%, $80=10%, $180=10%, $480=15%, $1,000=15%, $1,800=20%.
UPDATE cash_lot_plans SET referral_bps = CASE price_minor
  WHEN 4000 THEN 500 WHEN 8000 THEN 1000 WHEN 18000 THEN 1000
  WHEN 48000 THEN 1500 WHEN 100000 THEN 1500 WHEN 180000 THEN 2000 END
WHERE referral_bps IS NULL AND price_minor IN (4000, 8000, 18000, 48000, 100000, 180000);
