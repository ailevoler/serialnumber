-- XGEN LIFE Intl v16: rename "EXGEN Bonus" to "XGEN Bonus" in records already saved.
SET NAMES utf8mb4;
UPDATE gl_accounts SET name = 'Commission - XGEN Bonus' WHERE code = '5050';
UPDATE earnings SET description = REPLACE(description, 'EXGEN', 'XGEN') WHERE description LIKE '%EXGEN%';
UPDATE wallet_transactions SET description = REPLACE(description, 'EXGEN', 'XGEN') WHERE description LIKE '%EXGEN%';
UPDATE journal_entries SET memo = REPLACE(memo, 'EXGEN', 'XGEN') WHERE memo LIKE '%EXGEN%';
