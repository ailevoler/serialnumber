-- Per-Lot referral bonus percentage.
-- Import after 20260925_client_adjustments.sql.
-- cash_lot_plans.referral_bps: referral % for this package in basis points (500 = 5.00%); NULL uses the global referral setting.
-- cash_lots.referral_bps: snapshot taken at purchase so later package edits never change a bought Lot's bonus.
-- The app also adds these columns automatically if this file is not imported.

ALTER TABLE cash_lot_plans ADD COLUMN IF NOT EXISTS referral_bps INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE cash_lots ADD COLUMN IF NOT EXISTS referral_bps INT UNSIGNED NULL DEFAULT NULL;

-- Client referral bonus per Lot: Lot 1=5%, Lot 2=10%, Lot 3=10%, Lot 4=15%, Lot 5=15%, Lot 6=20%.
UPDATE cash_lot_plans SET referral_bps = CASE name
  WHEN 'Lot 1' THEN 500 WHEN 'Lot 2' THEN 1000 WHEN 'Lot 3' THEN 1000
  WHEN 'Lot 4' THEN 1500 WHEN 'Lot 5' THEN 1500 WHEN 'Lot 6' THEN 2000 END
WHERE referral_bps IS NULL AND name IN ('Lot 1','Lot 2','Lot 3','Lot 4','Lot 5','Lot 6');

-- Same percentages for packages not named "Lot N" but still at the original Lot prices ($40 … $1,800).
UPDATE cash_lot_plans SET referral_bps = CASE price_minor
  WHEN 4000 THEN 500 WHEN 8000 THEN 1000 WHEN 18000 THEN 1000
  WHEN 48000 THEN 1500 WHEN 100000 THEN 1500 WHEN 180000 THEN 2000 END
WHERE referral_bps IS NULL AND price_minor IN (4000, 8000, 18000, 48000, 100000, 180000);
