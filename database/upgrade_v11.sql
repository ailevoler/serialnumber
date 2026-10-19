-- XGEN LIFE Intl v11: Coded Bonus is 1-Up (per the marketing plan author).
--   Invite #1 of every member passes up to the coded upline; from invite #2 onward the sponsor earns the Coded Bonus.
--   Applies to new registrations only; bonuses already paid are not changed.
UPDATE settings SET svalue = '1' WHERE skey = 'passup_positions';
