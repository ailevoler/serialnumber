-- Cancellation fees and reasons. Import once after pay_methods_migration.sql. Safe to run again (MariaDB).
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS cancel_fee_centavos INT UNSIGNED NOT NULL DEFAULT 0 AFTER cancel_reason;
ALTER TABLE uride_wallet_tx MODIFY type ENUM('topup','ride_fare','commission','payout','payout_returned','adjustment','late_fee','damage_claim','cancel_fee') NOT NULL;
ALTER TABLE mk_bookings ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(200) DEFAULT NULL AFTER status_note;
ALTER TABLE mk_bookings ADD COLUMN IF NOT EXISTS cancel_fee_centavos INT UNSIGNED NOT NULL DEFAULT 0 AFTER cancel_reason;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(200) DEFAULT NULL;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS cancel_fee_centavos INT UNSIGNED NOT NULL DEFAULT 0;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('cancel.uride_pct', '20'), ('cancel.uride_free_min', '2'),
    ('cancel.booking_pct', '25'),
    ('cancel.ueat_pct', '30');
