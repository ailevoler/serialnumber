-- Face match (selfie vs ID photo) for KYC, and Face Login (liveness + face recognition) for customers.
-- Face descriptors are 128 numbers made on the phone by face-api (open source, MIT). Photos are not sent for login.
-- Import once after database/kyc_migration.sql. Safe to run again (MariaDB).
ALTER TABLE kyc_submissions ADD COLUMN IF NOT EXISTS face_descriptor TEXT DEFAULT NULL COMMENT 'Selfie (look straight) face descriptor, JSON 128 floats';
ALTER TABLE kyc_submissions ADD COLUMN IF NOT EXISTS id_face_descriptor TEXT DEFAULT NULL COMMENT 'Face on the ID photo, JSON 128 floats';
ALTER TABLE kyc_submissions ADD COLUMN IF NOT EXISTS face_match_distance DECIMAL(6,4) DEFAULT NULL COMMENT 'Selfie vs ID: lower = more alike; same person usually < 0.55';
ALTER TABLE kyc_submissions ADD COLUMN IF NOT EXISTS face_model VARCHAR(40) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS face_login_devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the device cookie',
    label VARCHAR(120) DEFAULT NULL,
    descriptor TEXT NOT NULL COMMENT 'Face set up on this phone (matched to the approved KYC face), JSON 128 floats',
    kyc_id BIGINT UNSIGNED DEFAULT NULL,
    fails INT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    UNIQUE KEY token_hash (token_hash),
    KEY user_devices (user_id, revoked_at),
    CONSTRAINT face_login_devices_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS face_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    device_id INT UNSIGNED DEFAULT NULL,
    purpose ENUM('login','enroll') NOT NULL DEFAULT 'login',
    ok TINYINT(1) NOT NULL,
    distance DECIMAL(6,4) DEFAULT NULL,
    reason VARCHAR(120) DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY user_time (user_id, created_at),
    KEY device_time (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('face.login_enabled', '1'),
    ('face.login_max_distance', '0.45'),
    ('face.kyc_max_distance', '0.60');
