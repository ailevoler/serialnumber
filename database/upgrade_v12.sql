-- XGEN LIFE Intl v12: the top of line (root account) follows the 2-Up rule too.
--   Its own invites #1 and #2 have no upline to pass to, so no Coded Bonus is paid for them (the company keeps it).
--   The Top earns the Coded Bonus from invite #3 onward and from the #1/#2 of its kept invites, like every member.
INSERT INTO settings (skey, svalue) VALUES ('passup_top_keeps', '0') ON DUPLICATE KEY UPDATE svalue = '0';
