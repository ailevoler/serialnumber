-- XGEN LIFE Intl v8: apply the official compensation plan.
--   1. Direct Selling Discount by tier (Silver 25%, Gold 30%, Platinum 35%, Diamond 40%;
--      stockists: Mobile Stockist 43%, City Hub 47%, Mega Depot 52%)
--   2. Direct Referral Bonus (outright): Silver 140, Gold 550, Platinum 1000, Diamond 1700
--   3. Coded Bonus (Pass-Up, 2-Up): Silver 500, Gold 2000, Platinum 3500, Diamond 6000
--   4. Pairing Bonus (Product Wallet): Silver↔Silver 100, Gold↔Gold 400, Platinum↔Platinum 700,
--      Diamond↔Diamond 1200, maximum 7 pairs a day
--   5. Unilevel: 5 pesos per bottle/pouch, 10 levels
-- Package PRICES below are placeholders — set the real prices in Admin → Compensation Plan.
SET NAMES utf8mb4;

ALTER TABLE packages ADD COLUMN coded_bonus DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER direct_bonus;
ALTER TABLE packages ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER coded_bonus;
ALTER TABLE users ADD COLUMN distributor_tier VARCHAR(40) NULL AFTER package_id;

UPDATE packages SET name = 'Silver',   price = 1500,  pv = 100,  direct_bonus = 140,  coded_bonus = 500,  discount_percent = 25, description = 'Silver package' WHERE id = 1;
UPDATE packages SET name = 'Gold',     price = 5000,  pv = 400,  direct_bonus = 550,  coded_bonus = 2000, discount_percent = 30, description = 'Gold package' WHERE id = 2;
UPDATE packages SET name = 'Platinum', price = 8000,  pv = 700,  direct_bonus = 1000, coded_bonus = 3500, discount_percent = 35, description = 'Platinum package' WHERE id = 3;
INSERT INTO packages (name, price, pv, direct_bonus, coded_bonus, discount_percent, description)
SELECT 'Diamond', 12000, 1200, 1700, 6000, 40, 'Diamond package' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Diamond');

INSERT IGNORE INTO products (sku, name, description, srp, member_price, pv, stock) VALUES
 ('NUT-6IN1', 'Nutriva 6-in-1 Coffee', '6-in-1 functional coffee. SRP ₱300 per pouch.', 300.00, 225.00, 5.00, 500),
 ('MAG-60ML', 'Magnesium Spray 60ml', 'Topical magnesium spray, 60 ml. SRP ₱900 per bottle.', 900.00, 675.00, 5.00, 300);

INSERT IGNORE INTO settings (skey, svalue) VALUES
 ('passup_kept_bonus', '1'), ('pairing_mode', 'matching'), ('pair_rate', '100'),
 ('unilevel_mode', 'per_unit'), ('unilevel_per_unit', '5,5,5,5,5,5,5,5,5,5'),
 ('discount_mode', 'tier'), ('stockist_tiers', 'Mobile Stockist:43,City Hub:47,Mega Depot:52');

UPDATE settings SET svalue = CASE skey
  WHEN 'direct_enabled' THEN '1'
  WHEN 'direct_mode' THEN 'fixed'
  WHEN 'direct_outright' THEN '1'
  WHEN 'passup_enabled' THEN '1'
  WHEN 'passup_positions' THEN '1,2'
  WHEN 'passup_mode' THEN 'package'
  WHEN 'passup_kept_bonus' THEN '1'
  WHEN 'passup_sponsor_keeps_direct' THEN '1'
  WHEN 'pairing_enabled' THEN '1'
  WHEN 'pairing_mode' THEN 'matching'
  WHEN 'pair_rate' THEN '100'
  WHEN 'pair_daily_cap' THEN '7'
  WHEN 'pairing_to_product_wallet' THEN '1'
  WHEN 'unilevel_enabled' THEN '1'
  WHEN 'unilevel_mode' THEN 'per_unit'
  WHEN 'unilevel_per_unit' THEN '5,5,5,5,5,5,5,5,5,5'
  WHEN 'discount_enabled' THEN '1'
  WHEN 'discount_mode' THEN 'tier'
  WHEN 'stockist_tiers' THEN 'Mobile Stockist:43,City Hub:47,Mega Depot:52'
  ELSE svalue END
WHERE skey IN ('direct_enabled','direct_mode','direct_outright','passup_enabled','passup_positions','passup_mode','passup_kept_bonus',
  'passup_sponsor_keeps_direct','pairing_enabled','pairing_mode','pair_rate','pair_daily_cap','pairing_to_product_wallet',
  'unilevel_enabled','unilevel_mode','unilevel_per_unit','discount_enabled','discount_mode','stockist_tiers');
