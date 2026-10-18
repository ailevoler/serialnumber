-- XGEN LIFE Intl v9: Plan Simulator (actuarial report), System Reset and Admin Action PIN.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS simulation_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  params TEXT NOT NULL,
  results MEDIUMTEXT NULL,
  members_created INT UNSIGNED NOT NULL DEFAULT 0,
  net_profit DECIMAL(14,2) NULL,
  payout_ratio DECIMAL(9,2) NULL,
  backup_file VARCHAR(120) NULL,
  error VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
