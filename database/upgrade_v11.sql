-- XGEN LIFE Intl v11: Coded Bonus is 2-Up (confirmed).
--   Invites #1 and #2 of every member pass up to the coded upline; from invite #3 onward the sponsor earns the Coded Bonus.
--   New setting passup_top_keeps: 1 = a sponsor with no upline (the root account) earns the Coded Bonus of its own
--   invites #1 and #2; 0 = nobody earns it (the company keeps it).
--   Applies to new registrations only; bonuses already paid are not changed.
UPDATE settings SET svalue = '1,2' WHERE skey = 'passup_positions';
INSERT IGNORE INTO settings (skey, svalue) VALUES ('passup_top_keeps', '1');
