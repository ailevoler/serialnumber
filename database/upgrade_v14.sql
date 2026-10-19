-- XGEN LIFE Intl v14: Indirect Bonus per package (amount per level).
--   Silver 10, Gold 40, Platinum 70, Diamond 120 per level, 5 levels above the sponsor.
--   By default the amount follows the package of the new (joining) member.
SET NAMES utf8mb4;

ALTER TABLE packages ADD COLUMN indirect_bonus DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER exgen_bonus;

UPDATE packages SET indirect_bonus = 10  WHERE name = 'Silver';
UPDATE packages SET indirect_bonus = 40  WHERE name = 'Gold';
UPDATE packages SET indirect_bonus = 70  WHERE name = 'Platinum';
UPDATE packages SET indirect_bonus = 120 WHERE name = 'Diamond';

INSERT INTO settings (skey, svalue) VALUES ('indirect_mode', 'package') ON DUPLICATE KEY UPDATE svalue = 'package';
INSERT IGNORE INTO settings (skey, svalue) VALUES ('indirect_package_levels', '5'), ('indirect_basis', 'source');
