-- Run ONCE on a database created from an older schema.sql (before Giving/PayMongo was added):
--   mysql -u root -p pcec_app < database/upgrade_giving.sql
-- Fresh installs don't need this; schema.sql already includes everything.
SET NAMES utf8mb4;

ALTER TABLE churches
  ADD COLUMN photo VARCHAR(255) NULL AFTER description,
  ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER photo;

ALTER TABLE events
  ADD COLUMN venue VARCHAR(180) NULL AFTER location,
  ADD COLUMN fee INT UNSIGNED NOT NULL DEFAULT 0 AFTER image,
  ADD COLUMN fee_note VARCHAR(255) NULL AFTER fee,
  ADD COLUMN highlights VARCHAR(255) NULL AFTER fee_note,
  ADD COLUMN capacity INT UNSIGNED NULL AFTER highlights;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_speakers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  role VARCHAR(160) NULL,
  photo VARCHAR(255) NULL,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_schedule (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  time_label VARCHAR(40) NOT NULL,
  title VARCHAR(180) NOT NULL,
  description VARCHAR(500) NULL,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS giving_projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  goal_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- centavos
  image VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL UNIQUE,         -- our reference, e.g. PCEC-260101-AB12CD
  user_id INT UNSIGNED NULL,
  purpose ENUM('donation','event') NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount INT UNSIGNED NOT NULL,                  -- centavos
  method VARCHAR(20) NOT NULL DEFAULT 'qrph',    -- qrph | card | ewallet | bank
  status ENUM('pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  livemode TINYINT(1) NOT NULL DEFAULT 0,
  intent_id VARCHAR(64) NULL,                    -- PayMongo payment intent (QR Ph)
  checkout_id VARCHAR(64) NULL,                  -- PayMongo checkout session (card / e-wallet)
  provider_payment_id VARCHAR(64) NULL,          -- PayMongo pay_... id once paid
  qr_image MEDIUMTEXT NULL,                      -- base64 data URI from PayMongo
  qr_expires_at DATETIME NULL,
  payer_reference VARCHAR(100) NULL,             -- bank transfer reference given by the donor
  admin_note VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  checked_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (intent_id), INDEX (checkout_id), INDEX (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS donations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL UNIQUE,
  user_id INT UNSIGNED NULL,
  gift_type ENUM('one_time','recurring','project') NOT NULL DEFAULT 'one_time',
  frequency ENUM('monthly','quarterly','yearly') NULL,
  fund VARCHAR(80) NOT NULL DEFAULT 'General Fund',
  project_id INT UNSIGNED NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  message VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (project_id) REFERENCES giving_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_registrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  payment_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (event_id, user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO giving_projects (title, description, goal_amount, sort) VALUES
('Typhoon Relief — Eastern Visayas', 'Food packs, clean water and rebuilding materials for families affected by recent typhoons.', 50000000, 1),
('Church Planting in Mindanao', 'Support new congregations in unreached communities across Mindanao.', 100000000, 2),
('Pastors Training Scholarship', 'Theological training scholarships for rural pastors.', 30000000, 3);

INSERT IGNORE INTO settings (k, v) VALUES
('giving_payee', 'Philippine Council of Evangelical Churches'),
('giving_funds', 'General Fund,Missions,Disaster Relief,Church Planting'),
('giving_amounts', '100,500,1000,2500,5000'),
('paymongo_mode', 'test'),
('paymongo_methods', 'qrph,card,gcash,paymaya,grab_pay'),
('bank_enabled', '1'),
('bank_name', 'BDO Unibank'),
('bank_account_name', 'Philippine Council of Evangelical Churches'),
('bank_account_number', '0000-0000-0000'),
('bank_instructions', 'Use your PCEC reference number as the transfer note, then submit your bank reference below.');
