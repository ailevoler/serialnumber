-- XGEN LIFE Intl v13: XGEN Bonus (Balik Puhunan).
--   Per direct the sponsor builds up the XGEN value of its own package: Silver 250, Gold 1,000,
--   Platinum 1,750, Diamond 3,000. A direct on a lower package counts only that package's value.
--   Target = value x 8 (Silver 2,000, Gold 8,000, Platinum 14,000, Diamond 24,000).
--   Paid once the member has at least 8 paid directs AND the counted value reaches the target.
SET NAMES utf8mb4;

ALTER TABLE earnings MODIFY type ENUM('discount','direct','indirect','passup','pairing','unilevel','exgen') NOT NULL;
ALTER TABLE packages ADD COLUMN exgen_bonus DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_percent;

UPDATE packages SET exgen_bonus = 250  WHERE name = 'Silver';
UPDATE packages SET exgen_bonus = 1000 WHERE name = 'Gold';
UPDATE packages SET exgen_bonus = 1750 WHERE name = 'Platinum';
UPDATE packages SET exgen_bonus = 3000 WHERE name = 'Diamond';

INSERT IGNORE INTO gl_accounts (code, name, type, is_system, description) VALUES
 ('5050', 'Commission - XGEN Bonus', 'expense', 1, 'Balik Puhunan bonus after 8 directs');

INSERT IGNORE INTO settings (skey, svalue) VALUES
 ('exgen_enabled', '1'), ('exgen_min_directs', '8'), ('exgen_mode', 'complete'), ('acct_comm_exgen', '5050');
-- Time limit: the target must be reached within 2 months, otherwise the progress resets (0 = no limit).
INSERT IGNORE INTO settings (skey, svalue) VALUES ('exgen_window_months', '2');
