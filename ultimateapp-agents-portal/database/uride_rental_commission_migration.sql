-- URide Rent a Ride commission: Ultimate App keeps 15% of each completed rental (with driver and self-drive),
-- and of late-return fees. Rides keep their own trip fee (PHP 10 per trip). Safe to run again.
INSERT INTO app_settings (setting_key, setting_value) VALUES ('uride.rental_commission_bp', '1500')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
