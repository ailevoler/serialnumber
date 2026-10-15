-- URide pricing (October 2026). Safe to run again; it sets these values each time it is imported.
-- Motorcycle and E-Trike: flag-down PHP 50.00 covers the first 2 km, then PHP 25.00 per km (4 km = PHP 100.00).
-- Drivers: PHP 10.00 deducted per completed trip; minimum top-up PHP 50.00; PHP 50.00 in the wallet to go online.
INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('uride.motorcycle.enabled', '1'), ('uride.motorcycle.base_centavos', '5000'), ('uride.motorcycle.included_km', '2'),
    ('uride.motorcycle.per_km_centavos', '2500'), ('uride.motorcycle.minimum_centavos', '5000'),
    ('uride.e_trike.enabled', '1'), ('uride.e_trike.base_centavos', '5000'), ('uride.e_trike.included_km', '2'),
    ('uride.e_trike.per_km_centavos', '2500'), ('uride.e_trike.minimum_centavos', '5000'),
    ('uride.commission_type', 'fixed'), ('uride.commission_fixed_centavos', '1000'),
    ('uride.topup_min_centavos', '5000'), ('uride.min_wallet_centavos', '5000')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
