-- XGEN LIFE Intl - MLM System Database Schema
-- MySQL 5.7+ / MariaDB 10.3+  (utf8mb4)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS expenses, journal_lines, journal_entries, gl_accounts, crm_activities, crm_leads, chat_messages, chat_threads, payments, activity_logs, announcements, settings, payouts, wallet_transactions,
  earnings, order_items, orders, products, codes, users, packages;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE packages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  pv DECIMAL(12,2) NOT NULL DEFAULT 0,
  direct_bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(40) NOT NULL UNIQUE,
  email VARCHAR(120) NOT NULL,
  password VARCHAR(255) NOT NULL,
  first_name VARCHAR(60) NOT NULL,
  last_name VARCHAR(60) NOT NULL,
  mobile VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  role ENUM('member','admin') NOT NULL DEFAULT 'member',
  status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
  package_id INT UNSIGNED NULL,
  sponsor_id INT UNSIGNED NULL,
  placement_id INT UNSIGNED NULL,
  position ENUM('L','R') NULL,
  passup_upline_id INT UNSIGNED NULL,
  direct_count INT UNSIGNED NOT NULL DEFAULT 0,
  left_count INT UNSIGNED NOT NULL DEFAULT 0,
  right_count INT UNSIGNED NOT NULL DEFAULT 0,
  left_points DECIMAL(14,2) NOT NULL DEFAULT 0,
  right_points DECIMAL(14,2) NOT NULL DEFAULT 0,
  wallet_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_earnings DECIMAL(14,2) NOT NULL DEFAULT 0,
  payout_method VARCHAR(40) NULL,
  payout_account_name VARCHAR(120) NULL,
  payout_account_number VARCHAR(60) NULL,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slot (placement_id, position),
  KEY idx_sponsor (sponsor_id),
  KEY idx_role (role),
  CONSTRAINT fk_users_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
  CONSTRAINT fk_users_sponsor FOREIGN KEY (sponsor_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_users_placement FOREIGN KEY (placement_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activation codes (serial number + PIN) used to register / activate members
CREATE TABLE codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  pin VARCHAR(10) NOT NULL,
  package_id INT UNSIGNED NOT NULL,
  code_type ENUM('paid','free') NOT NULL DEFAULT 'paid',
  owner_id INT UNSIGNED NULL,
  used_by INT UNSIGNED NULL,
  status ENUM('unused','used','blocked') NOT NULL DEFAULT 'unused',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME NULL,
  KEY idx_owner (owner_id, status),
  CONSTRAINT fk_codes_package FOREIGN KEY (package_id) REFERENCES packages(id),
  CONSTRAINT fk_codes_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_codes_used FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  image VARCHAR(255) NULL,
  srp DECIMAL(12,2) NOT NULL DEFAULT 0,
  member_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  pv DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  subtotal_srp DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_pv DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_method ENUM('wallet','cash','bank','gcash','maya','paymongo') NOT NULL DEFAULT 'wallet',
  payment_ref VARCHAR(100) NULL,
  shipping_address VARCHAR(255) NULL,
  status ENUM('pending','paid','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  commission_processed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  KEY idx_user (user_id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(120) NOT NULL,
  qty INT UNSIGNED NOT NULL,
  srp DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  pv DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Income log for the 6 ways to earn
CREATE TABLE earnings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('discount','direct','indirect','passup','pairing','unilevel') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  source_user_id INT UNSIGNED NULL,
  order_id INT UNSIGNED NULL,
  level INT UNSIGNED NULL,
  pairs INT UNSIGNED NOT NULL DEFAULT 0,
  credited TINYINT(1) NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, type),
  KEY idx_created (created_at),
  CONSTRAINT fk_earn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wallet_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  direction ENUM('credit','debit') NOT NULL,
  category ENUM('earning','payout','payout_refund','purchase','purchase_refund','transfer_in','transfer_out','adjustment','code_purchase') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  balance_after DECIMAL(14,2) NOT NULL,
  reference VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id, created_at),
  CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payouts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(40) NOT NULL,
  account_name VARCHAR(120) NOT NULL,
  account_number VARCHAR(60) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_note VARCHAR(255) NULL,
  processed_by INT UNSIGNED NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status),
  CONSTRAINT fk_payout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Online payments (PayMongo checkout sessions) for product orders and code purchases
CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(30) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('order','codes') NOT NULL,
  order_id INT UNSIGNED NULL,
  package_id INT UNSIGNED NULL,
  qty INT UNSIGNED NOT NULL DEFAULT 0,
  amount DECIMAL(12,2) NOT NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'paymongo',
  checkout_id VARCHAR(80) NULL UNIQUE,
  checkout_url TEXT NULL,
  status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  skey VARCHAR(60) PRIMARY KEY,
  svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('published','draft') NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activity_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(255) NOT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------
INSERT INTO packages (id, name, price, pv, direct_bonus, description) VALUES
 (1, 'Starter',  1500.00, 100.00, 150.00, 'Entry package with starter products'),
 (2, 'Business', 3500.00, 250.00, 400.00, 'Business package with more products'),
 (3, 'Elite',    7500.00, 500.00, 900.00, 'Elite package with full product set');

-- Default logins (CHANGE AFTER INSTALL):
--   Administrator : admin    / Admin@123
--   Company head  : xgenlife / Xgen@123   (root of the genealogy)
INSERT INTO users (id, username, email, password, first_name, last_name, role, status, package_id) VALUES
 (1, 'admin', 'admin@xgenlife.com', '$2y$10$aX85BUxg8NNjUJ/uDtCGteLpcAwmPmYSf9ZMUTXHonTP4jhwWhE6a', 'System', 'Administrator', 'admin', 'active', NULL),
 (2, 'xgenlife', 'company@xgenlife.com', '$2y$10$J9Qj28iQyu0.g9Gva2DgseW6P1xkSTjUXA/mom3BKUo1HuRK2GEtK', 'XGEN', 'Life Intl', 'member', 'active', 3);

INSERT INTO products (sku, name, description, srp, member_price, pv, stock) VALUES
 ('XG-001', 'XGEN Barley Plus', 'Pure organic barley grass powder drink, 30 sachets.', 1200.00, 950.00, 50.00, 200),
 ('XG-002', 'XGEN Coffee 12-in-1', 'Healthy coffee blend with natural herbs, 20 sachets.', 650.00, 500.00, 25.00, 300),
 ('XG-003', 'XGEN Moringa Caps', 'Malunggay capsules for daily nutrition, 60 caps.', 800.00, 620.00, 30.00, 150),
 ('XG-004', 'XGEN Glutathione Soap', 'Whitening and anti-aging soap, 135g.', 250.00, 180.00, 10.00, 500);

INSERT INTO settings (skey, svalue) VALUES
 ('site_name', 'XGEN LIFE Intl'),
 ('site_tagline', 'Better Health • Brighter Future. Together.'),
 ('currency', '₱'),
 -- Compensation plan (all editable in Admin → Compensation Plan)
 ('discount_enabled', '1'),
 ('direct_enabled', '1'),
 ('direct_mode', 'fixed'),
 ('direct_percent', '10'),
 ('indirect_enabled', '1'),
 ('indirect_mode', 'fixed'),
 ('indirect_levels', '50,30,20,10,10'),
 ('passup_enabled', '1'),
 ('passup_positions', '1'),
 ('passup_mode', 'fixed'),
 ('passup_bonus', '100'),
 ('passup_sponsor_keeps_direct', '1'),
 ('pairing_enabled', '1'),
 ('pair_pv', '100'),
 ('pair_bonus', '200'),
 ('pair_daily_cap', '10'),
 ('unilevel_enabled', '1'),
 ('unilevel_base', 'pv'),
 ('unilevel_percent', '10,5,5,3,2,2,1,1,1,1'),
 ('unilevel_require_active', '1'),
 -- Wallet & payouts
 ('payout_min', '500'),
 ('payout_fee_percent', '10'),
 ('payout_fee_fixed', '0'),
 ('payout_methods', 'GCash,Maya,BDO,BPI,Cash Pick-up'),
 ('wallet_transfer_enabled', '1'),
 ('wallet_transfer_min', '100'),
 ('order_payment_methods', 'wallet,cash,bank,gcash,maya'),
 ('company_payment_info', 'GCash/Maya: 09XX-XXX-XXXX (XGEN LIFE Intl)'),
 ('code_purchase_enabled', '1'),
 ('code_purchase_wallet', '1'),
 -- PayMongo
 ('paymongo_enabled', '0'),
 ('paymongo_mode', 'test'),
 ('paymongo_public_key', ''),
 ('paymongo_secret_key', ''),
 ('paymongo_webhook_secret', ''),
 ('paymongo_webhook_id', ''),
 ('paymongo_methods', 'qrph');

INSERT INTO announcements (title, body) VALUES
 ('Welcome to XGEN LIFE Intl!', 'Better Health • Brighter Future. Together. Start building your team by sharing your referral link.');

-- ---------------------------------------------------------------
-- v3 modules: Live Chat, CRM, ERP Finance
-- ---------------------------------------------------------------
SET NAMES utf8mb4;

-- ---------------------------------------------------------------
-- Live Chat Support
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_threads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  subject VARCHAR(150) NOT NULL DEFAULT 'Support chat',
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  assigned_admin_id INT UNSIGNED NULL,
  unread_admin INT UNSIGNED NOT NULL DEFAULT 0,
  unread_member INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id, status),
  KEY idx_status (status, last_message_at),
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NULL,
  sender_role ENUM('member','admin','system') NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_thread (thread_id, id),
  CONSTRAINT fk_msg_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- CRM
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NULL,          -- NULL = company lead, else the member who owns the prospect
  assigned_admin_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  mobile VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  location VARCHAR(120) NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'Referral',
  interest ENUM('product','business','both') NOT NULL DEFAULT 'both',
  status ENUM('new','contacted','presented','follow_up','converted','lost') NOT NULL DEFAULT 'new',
  next_follow_up DATE NULL,
  notes TEXT NULL,
  converted_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_owner (owner_user_id, status),
  KEY idx_follow (next_follow_up),
  CONSTRAINT fk_lead_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NULL,
  member_id INT UNSIGNED NULL,              -- activity about an existing member (member 360)
  author_id INT UNSIGNED NULL,
  type ENUM('note','call','sms','email','meeting','task') NOT NULL DEFAULT 'note',
  body TEXT NOT NULL,
  due_date DATE NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead (lead_id),
  KEY idx_member (member_id),
  CONSTRAINT fk_act_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_act_member FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- ERP Finance (double-entry general ledger, SAP-style documents)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gl_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(12) NOT NULL UNIQUE,
  doc_type CHAR(2) NOT NULL,                -- SA GL, DR sales, CM commission, ZP payout, KR expense, WA wallet adj, AB reversal
  posting_date DATE NOT NULL,
  memo VARCHAR(255) NOT NULL,
  ref_type VARCHAR(30) NULL,
  ref_id INT UNSIGNED NULL,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  reversal_of INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_date (posting_date),
  KEY idx_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  memo VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,                -- member sub-ledger
  KEY idx_account (account_id),
  CONSTRAINT fk_line_entry FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_line_account FOREIGN KEY (account_id) REFERENCES gl_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_date DATE NOT NULL,
  vendor VARCHAR(120) NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  paid_from_account_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reference VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exp_account FOREIGN KEY (account_id) REFERENCES gl_accounts(id),
  CONSTRAINT fk_exp_paid FOREIGN KEY (paid_from_account_id) REFERENCES gl_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE wallet_transactions MODIFY category ENUM('earning','payout','payout_refund','purchase','purchase_refund','transfer_in','transfer_out','adjustment','code_purchase') NOT NULL;

INSERT IGNORE INTO gl_accounts (code, name, type, is_system, description) VALUES
 ('1000', 'Cash on Hand', 'asset', 1, 'Cash collected at the office'),
 ('1010', 'Cash in Bank', 'asset', 1, 'Company bank account (payouts are released from here)'),
 ('1020', 'PayMongo Clearing', 'asset', 1, 'Online payments not yet settled by PayMongo'),
 ('1030', 'GCash / Maya Wallet', 'asset', 1, 'Company e-wallet accounts'),
 ('1100', 'Accounts Receivable', 'asset', 0, NULL),
 ('1200', 'Inventory', 'asset', 0, NULL),
 ('1500', 'Property & Equipment', 'asset', 0, NULL),
 ('2000', 'Members\' Wallet Payable', 'liability', 1, 'Total of all member e-wallet balances'),
 ('2010', 'Payouts Payable', 'liability', 1, 'Requested withdrawals not yet released'),
 ('2100', 'Accounts Payable', 'liability', 0, NULL),
 ('2200', 'Taxes Payable', 'liability', 0, NULL),
 ('3000', 'Owner\'s Capital', 'equity', 0, NULL),
 ('3100', 'Retained Earnings', 'equity', 0, NULL),
 ('4000', 'Package / Activation Sales', 'revenue', 1, 'Sale of activation codes / packages'),
 ('4100', 'Product Sales', 'revenue', 1, 'Product orders at member price'),
 ('4200', 'Payout Processing Fees', 'revenue', 1, 'Fees deducted from withdrawals'),
 ('4900', 'Other Income', 'revenue', 1, NULL),
 ('5000', 'Commission - Direct Referral', 'expense', 1, NULL),
 ('5010', 'Commission - Indirect', 'expense', 1, NULL),
 ('5020', 'Commission - Pass Up', 'expense', 1, NULL),
 ('5030', 'Commission - Pairing / Matching', 'expense', 1, NULL),
 ('5040', 'Commission - Unilevel', 'expense', 1, NULL),
 ('5100', 'Wallet Adjustments', 'expense', 1, 'Manual wallet credits by admin'),
 ('5200', 'Payment Gateway Fees', 'expense', 0, NULL),
 ('5300', 'Cost of Goods Sold', 'expense', 0, NULL),
 ('6000', 'Salaries & Wages', 'expense', 0, NULL),
 ('6100', 'Rent', 'expense', 0, NULL),
 ('6200', 'Utilities', 'expense', 0, NULL),
 ('6300', 'Marketing & Events', 'expense', 0, NULL),
 ('6400', 'Shipping & Delivery', 'expense', 0, NULL),
 ('6500', 'Office Supplies', 'expense', 0, NULL),
 ('6900', 'Miscellaneous Expense', 'expense', 0, NULL);

INSERT IGNORE INTO settings (skey, svalue) VALUES
 ('chat_enabled', '1'),
 ('chat_welcome', 'Hi! 👋 How can we help you today? Our support team will reply shortly.'),
 ('chat_hours', 'Mon–Sat, 8:00 AM – 6:00 PM'),
 ('chat_quick_replies', "Hello! Thank you for contacting XGEN LIFE support.\nWe are checking this for you, please wait a moment.\nYour payout has been processed. Please check your account.\nIs there anything else we can help you with?"),
 ('crm_sources', 'Facebook,Referral,Walk-in,Event,Website,TikTok,Other'),
 ('erp_enabled', '1'),
 ('erp_lock_date', ''),
 ('acct_cash', '1000'),
 ('acct_bank', '1010'),
 ('acct_paymongo', '1020'),
 ('acct_ewallet', '1030'),
 ('acct_wallet_payable', '2000'),
 ('acct_payouts_payable', '2010'),
 ('acct_package_sales', '4000'),
 ('acct_product_sales', '4100'),
 ('acct_payout_fees', '4200'),
 ('acct_other_income', '4900'),
 ('acct_comm_direct', '5000'),
 ('acct_comm_indirect', '5010'),
 ('acct_comm_passup', '5020'),
 ('acct_comm_pairing', '5030'),
 ('acct_comm_unilevel', '5040'),
 ('acct_wallet_adjust', '5100'),
 ('acct_payout_source', '1010');
