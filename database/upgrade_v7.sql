-- XGEN LIFE Intl v7: Product Wallet (binary pairing option) and outright Direct Referral Bonus.
SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN product_wallet DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER wallet_balance;
ALTER TABLE wallet_transactions ADD COLUMN wallet ENUM('ewallet','product') NOT NULL DEFAULT 'ewallet' AFTER user_id;
ALTER TABLE earnings ADD COLUMN paid_to ENUM('ewallet','product','outright','none') NOT NULL DEFAULT 'ewallet' AFTER credited;
UPDATE earnings SET paid_to = 'none' WHERE credited = 0;
ALTER TABLE orders MODIFY payment_method ENUM('wallet','product_wallet','cash','bank','gcash','maya','paymongo') NOT NULL DEFAULT 'wallet';

INSERT IGNORE INTO gl_accounts (code, name, type, is_system, description) VALUES
 ('2020', 'Members\' Product Wallet Payable', 'liability', 1, 'Product-only wallet balances (e.g. pairing bonus credited as product wallet)');

INSERT IGNORE INTO settings (skey, svalue) VALUES
 ('pairing_to_product_wallet', '0'),
 ('direct_outright', '1'),
 ('acct_product_wallet_payable', '2020'),
 ('acct_outright_source', '1000');
