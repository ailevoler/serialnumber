-- XGEN LIFE Intl - MLM System Database Schema
-- MySQL 5.7+ / MariaDB 10.3+  (utf8mb4)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activity_logs, announcements, settings, payouts, wallet_transactions,
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
  payment_method ENUM('wallet','cash','bank','gcash','maya') NOT NULL DEFAULT 'wallet',
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
  category ENUM('earning','payout','payout_refund','purchase','purchase_refund','transfer_in','transfer_out','adjustment') NOT NULL,
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
 ('indirect_levels', '50,30,20,10,10'),
 ('passup_enabled', '1'),
 ('passup_positions', '1'),
 ('passup_bonus', '100'),
 ('pair_pv', '100'),
 ('pair_bonus', '200'),
 ('pair_daily_cap', '10'),
 ('unilevel_percent', '10,5,5,3,2,2,1,1,1,1'),
 ('unilevel_require_active', '1'),
 ('payout_min', '500'),
 ('payout_fee_percent', '10'),
 ('payout_fee_fixed', '0'),
 ('payout_methods', 'GCash,Maya,BDO,BPI,Cash Pick-up'),
 ('wallet_transfer_enabled', '1'),
 ('wallet_transfer_min', '100'),
 ('order_payment_methods', 'wallet,cash,bank,gcash,maya'),
 ('company_payment_info', 'GCash/Maya: 09XX-XXX-XXXX (XGEN LIFE Intl)');

INSERT INTO announcements (title, body) VALUES
 ('Welcome to XGEN LIFE Intl!', 'Better Health • Brighter Future. Together. Start building your team by sharing your referral link.');
