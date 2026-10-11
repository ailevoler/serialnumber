-- URide Self-Drive rentals: the vehicle owner hands over the vehicle, the customer drives.
-- Needs: customer KYC (ID) + driver's license on file, a security deposit held in Credits, return check.
-- Import once AFTER database/uride_rental_migration.sql. Safe to run again (MariaDB).
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS rental_mode ENUM('driver','self') DEFAULT NULL COMMENT 'Rentals: with driver or self-drive' AFTER rental_package;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS ends_at DATETIME DEFAULT NULL COMMENT 'Rental end (start + hours), set when the rental starts' AFTER scheduled_at;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS deposit_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER held_centavos;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS deposit_status ENUM('none','held','refunded','claimed','settled') NOT NULL DEFAULT 'none' AFTER deposit_centavos;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS license_id INT UNSIGNED DEFAULT NULL AFTER deposit_status;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS handover_note VARCHAR(300) DEFAULT NULL AFTER license_id;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS returned_at DATETIME DEFAULT NULL AFTER handover_note;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS late_fee_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER returned_at;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS claim_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER late_fee_centavos;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS claim_note VARCHAR(300) DEFAULT NULL AFTER claim_centavos;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS claim_approved_centavos BIGINT UNSIGNED DEFAULT NULL AFTER claim_note;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS claim_decided_by INT UNSIGNED DEFAULT NULL AFTER claim_approved_centavos;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS claim_decided_at DATETIME DEFAULT NULL AFTER claim_decided_by;
ALTER TABLE uride_requests ADD INDEX IF NOT EXISTS deposit_status (deposit_status);

ALTER TABLE uride_drivers ADD COLUMN IF NOT EXISTS self_drive_ok TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Driver offers their vehicle for self-drive rentals';

CREATE TABLE IF NOT EXISTS user_driver_licenses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    license_no VARCHAR(30) NOT NULL,
    expiry DATE NOT NULL,
    can_motorcycle TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Motorcycle / tricycle (A, A1)',
    can_car TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Car (B)',
    photo_file VARCHAR(80) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(255) DEFAULT NULL,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY user_latest (user_id, id),
    KEY status (status, id),
    CONSTRAINT user_driver_licenses_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defaults (Admin › Settings › URide › Self-Drive). Prices in centavos; set your real rates.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('uride.self_drive_enabled', '1'),
    ('uride.self_drive_kyc', '1'),
    ('uride.self_drive_grace_min', '15'),
    ('uride.e_trike.self_enabled', '1'), ('uride.e_trike.self_hour_centavos', '35000'), ('uride.e_trike.self_min_hours', '2'), ('uride.e_trike.self_day_centavos', '200000'), ('uride.e_trike.self_deposit_centavos', '200000'),
    ('uride.motorcycle.self_enabled', '1'), ('uride.motorcycle.self_hour_centavos', '20000'), ('uride.motorcycle.self_min_hours', '2'), ('uride.motorcycle.self_day_centavos', '120000'), ('uride.motorcycle.self_deposit_centavos', '300000'),
    ('uride.car.self_enabled', '1'), ('uride.car.self_hour_centavos', '60000'), ('uride.car.self_min_hours', '4'), ('uride.car.self_day_centavos', '350000'), ('uride.car.self_deposit_centavos', '500000');

-- Driver wallet entries for self-drive late fees and approved damage claims (paid from the customer's deposit).
ALTER TABLE uride_wallet_tx MODIFY type ENUM('topup','ride_fare','commission','payout','payout_returned','adjustment','late_fee','damage_claim') NOT NULL;
