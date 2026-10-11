-- URide: E-Bike and Van (10-12 seater) vehicle types, and rental fleets (an owner can rent out many units).
-- Import once after the URide rental and self-drive migrations. Safe to run again (MariaDB).
ALTER TABLE uride_requests MODIFY vehicle_type ENUM('e_bike','motorcycle','e_trike','car','van') NOT NULL;
ALTER TABLE uride_drivers MODIFY vehicle_type ENUM('e_bike','motorcycle','e_trike','car','van') NOT NULL;

-- Self-drive units per owner and vehicle type. Customers see how many are available; an owner can rent out up to
-- this many at the same time.
CREATE TABLE IF NOT EXISTS uride_fleet (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    vehicle_type ENUM('e_bike','motorcycle','e_trike','car','van') NOT NULL,
    units SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    model VARCHAR(80) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY fleet_owner_type (driver_id, vehicle_type),
    KEY fleet_type (vehicle_type, units),
    CONSTRAINT uride_fleet_driver_fk FOREIGN KEY (driver_id) REFERENCES uride_drivers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Owners who already offered their own vehicle for self-drive keep it as 1 unit.
INSERT IGNORE INTO uride_fleet (driver_id, vehicle_type, units, model)
SELECT id, vehicle_type, 1, vehicle_model FROM uride_drivers WHERE self_drive_ok = 1;

-- Rates for the new vehicles (centavos). E-Bike: rental only (no rides), self-drive by default. Change in Admin › Settings › URide.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('uride.e_bike.enabled', '0'),
    ('uride.e_bike.rental_enabled', '0'), ('uride.e_bike.rental_hour_centavos', '15000'), ('uride.e_bike.rental_min_hours', '1'), ('uride.e_bike.rental_day_centavos', '80000'),
    ('uride.e_bike.self_enabled', '1'), ('uride.e_bike.self_hour_centavos', '10000'), ('uride.e_bike.self_min_hours', '1'), ('uride.e_bike.self_day_centavos', '60000'), ('uride.e_bike.self_deposit_centavos', '100000'),
    ('uride.van.enabled', '1'), ('uride.van.base_centavos', '30000'), ('uride.van.included_km', '1'), ('uride.van.per_km_centavos', '4000'), ('uride.van.minimum_centavos', '30000'),
    ('uride.van.rental_enabled', '1'), ('uride.van.rental_hour_centavos', '150000'), ('uride.van.rental_min_hours', '3'), ('uride.van.rental_day_centavos', '900000'),
    ('uride.van.self_enabled', '1'), ('uride.van.self_hour_centavos', '120000'), ('uride.van.self_min_hours', '4'), ('uride.van.self_day_centavos', '700000'), ('uride.van.self_deposit_centavos', '1000000'),
    ('uride.e_bike_license', '0');
