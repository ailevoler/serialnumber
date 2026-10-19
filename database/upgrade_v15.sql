-- XGEN LIFE Intl v15: XGEN Bonus counts only directs on the SAME package as the member
--   (Silver counts Silver directs, Gold counts Gold, Platinum counts Platinum, Diamond counts Diamond).
INSERT INTO settings (skey, svalue) VALUES ('exgen_count_rule', 'same') ON DUPLICATE KEY UPDATE svalue = 'same';
