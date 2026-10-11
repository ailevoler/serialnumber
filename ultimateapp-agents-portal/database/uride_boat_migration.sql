-- URide: Boat for point-to-point rides only (no hourly rental, no self-drive).
-- Import once after uride_fleet_migration.sql. Safe to run again (MariaDB).
ALTER TABLE uride_requests MODIFY vehicle_type ENUM('e_bike','motorcycle','e_trike','car','van','boat') NOT NULL;
ALTER TABLE uride_drivers MODIFY vehicle_type ENUM('e_bike','motorcycle','e_trike','car','van','boat') NOT NULL;
ALTER TABLE uride_fleet MODIFY vehicle_type ENUM('e_bike','motorcycle','e_trike','car','van','boat') NOT NULL;

-- Boat fares (centavos): ₱500 base covering the first km, ₱100 per km after, ₱500 minimum.
-- Change in Admin › Settings › URide › Fare matrix.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('uride.boat.enabled', '1'), ('uride.boat.base_centavos', '50000'), ('uride.boat.included_km', '1'),
    ('uride.boat.per_km_centavos', '10000'), ('uride.boat.minimum_centavos', '50000'),
    ('uride.boat.rental_enabled', '0'), ('uride.boat.self_enabled', '0');
