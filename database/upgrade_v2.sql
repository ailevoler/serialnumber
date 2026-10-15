-- Upgrade an existing v1 database to v2 (configurable compensation plan + PayMongo).
-- Fresh installs only need schema.sql.
SET NAMES utf8mb4;

ALTER TABLE orders MODIFY payment_method ENUM('wallet','cash','bank','gcash','maya','paymongo') NOT NULL DEFAULT 'wallet';
ALTER TABLE wallet_transactions MODIFY category ENUM('earning','payout','payout_refund','purchase','purchase_refund','transfer_in','transfer_out','adjustment','code_purchase') NOT NULL;

CREATE TABLE IF NOT EXISTS payments (
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

INSERT IGNORE INTO settings (skey, svalue) VALUES
 ('discount_enabled', '1'), ('direct_enabled', '1'), ('direct_mode', 'fixed'), ('direct_percent', '10'),
 ('indirect_enabled', '1'), ('indirect_mode', 'fixed'),
 ('passup_mode', 'fixed'), ('passup_sponsor_keeps_direct', '1'),
 ('pairing_enabled', '1'), ('unilevel_enabled', '1'), ('unilevel_base', 'pv'),
 ('code_purchase_enabled', '1'), ('code_purchase_wallet', '1'),
 ('paymongo_enabled', '0'), ('paymongo_mode', 'test'), ('paymongo_public_key', ''), ('paymongo_secret_key', ''),
 ('paymongo_webhook_secret', ''), ('paymongo_webhook_id', ''), ('paymongo_methods', 'gcash,paymaya,card');
