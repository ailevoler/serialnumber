-- XGEN LIFE Intl v10: Package Upgrade (Silver → Gold → Platinum → Diamond).
--   Members pay only the price difference (E-Wallet, QR Ph or an Upgrade Code).
--   The sponsor / coded upline earn the bonus difference and the PV difference goes to the binary.
SET NAMES utf8mb4;

ALTER TABLE codes MODIFY code_type ENUM('paid','free','upgrade') NOT NULL DEFAULT 'paid';
ALTER TABLE codes ADD COLUMN sale_method VARCHAR(12) NULL AFTER code_type;
ALTER TABLE payments MODIFY purpose ENUM('order','codes','upgrade') NOT NULL;
ALTER TABLE wallet_transactions MODIFY category ENUM('earning','payout','payout_refund','purchase','purchase_refund','transfer_in','transfer_out','adjustment','code_purchase','upgrade') NOT NULL;

CREATE TABLE IF NOT EXISTS package_upgrades (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  from_package_id INT UNSIGNED NULL,
  to_package_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  pv_added DECIMAL(12,2) NOT NULL DEFAULT 0,
  bonuses DECIMAL(12,2) NOT NULL DEFAULT 0,
  method VARCHAR(12) NOT NULL,
  reference VARCHAR(40) NULL,
  code_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_upg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (skey, svalue) VALUES
 ('upgrade_enabled', '1'), ('upgrade_allow_skip', '1'), ('upgrade_bonus', 'difference'),
 ('upgrade_add_pv', '1'), ('upgrade_wallet', '1');
