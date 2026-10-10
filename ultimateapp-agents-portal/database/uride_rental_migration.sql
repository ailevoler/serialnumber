-- URide Rent a Ride: hire a vehicle with its driver by the hour or for the whole day, now or scheduled.
-- Import once after the URide migrations. Safe to run again (MariaDB).
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS trip_type ENUM('ride','rental') NOT NULL DEFAULT 'ride' AFTER code;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS rental_hours TINYINT UNSIGNED DEFAULT NULL AFTER trip_type;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS rental_package VARCHAR(10) DEFAULT NULL COMMENT 'hours or day' AFTER rental_hours;
ALTER TABLE uride_requests ADD COLUMN IF NOT EXISTS scheduled_at DATETIME DEFAULT NULL COMMENT 'Rental start; NULL = as soon as possible' AFTER rental_package;
ALTER TABLE uride_requests ADD INDEX IF NOT EXISTS trip_status (trip_type, status, scheduled_at);

-- Defaults (Admin › Settings › URide › Rent a Ride). Prices in centavos; change them to your real rates.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('uride.rental_enabled', '1'),
    ('uride.rental_day_hours', '8'),
    ('uride.rental_max_hours', '12'),
    ('uride.rental_schedule_days', '7'),
    ('uride.e_trike.rental_enabled', '1'), ('uride.e_trike.rental_hour_centavos', '50000'), ('uride.e_trike.rental_min_hours', '1'), ('uride.e_trike.rental_day_centavos', '300000'),
    ('uride.motorcycle.rental_enabled', '1'), ('uride.motorcycle.rental_hour_centavos', '30000'), ('uride.motorcycle.rental_min_hours', '1'), ('uride.motorcycle.rental_day_centavos', '180000'),
    ('uride.car.rental_enabled', '1'), ('uride.car.rental_hour_centavos', '80000'), ('uride.car.rental_min_hours', '2'), ('uride.car.rental_day_centavos', '500000');
