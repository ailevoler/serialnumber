-- Payment methods: BCash for URide, UPass, UGo, ULocal and UEat, and an app-wide switch for cash ("Pay Cash").
-- Import once after uride_fleet_migration.sql and upass_credits_migration.sql. Safe to run again (MariaDB).
ALTER TABLE uride_requests MODIFY payment_method ENUM('credits','cash','bcash') NOT NULL;
ALTER TABLE mk_bookings MODIFY pay_method ENUM('credits','bcash','on_site') NOT NULL;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS pay_source ENUM('credits','bcash') NOT NULL DEFAULT 'credits' AFTER payment_status;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('pay.cash_enabled', '1'),
    ('pay.bcash_enabled', '1'),
    ('uride.bcash_enabled', '1');
