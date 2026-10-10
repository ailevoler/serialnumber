-- URide place search: landmarks managed in Admin › URide › Places.
-- Customers see these (plus approved merchants and their own recent places) as they type, even when
-- Google place search is unavailable. Import once. Safe to run again.
CREATE TABLE IF NOT EXISTS uride_places (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    area VARCHAR(120) DEFAULT NULL COMMENT 'Barangay or landmark area shown under the name',
    keywords VARCHAR(255) DEFAULT NULL COMMENT 'Other names people type, comma separated',
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = pin checked by an admin',
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY name (name),
    KEY active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Starter landmarks. Pins are APPROXIMATE (verified = 0): open Admin › URide › Places, drag each pin
-- to the exact spot and save to mark it verified.
INSERT IGNORE INTO uride_places (name, area, keywords, latitude, longitude, sort_order) VALUES
    ('D''Mall Boracay', 'Station 2, Balabag', 'dmall, d mall, station 2', 11.9667000, 121.9246000, 10),
    ('Station 1 (Willy''s Rock)', 'Balabag', 'station 1, willys rock, willy', 11.9797000, 121.9183000, 20),
    ('Station 2 Beachfront', 'Balabag', 'station 2, white beach', 11.9640000, 121.9250000, 30),
    ('Station 3', 'Manoc-Manoc', 'station 3', 11.9560000, 121.9300000, 40),
    ('Cagban Jetty Port', 'Manoc-Manoc', 'cagban, jetty, port, pier', 11.9469000, 121.9330000, 50),
    ('Tambisaan Jetty Port', 'Manoc-Manoc', 'tambisaan, jetty, port', 11.9548000, 121.9412000, 60),
    ('Bulabog Beach', 'Balabag', 'bulabog, kite', 11.9655000, 121.9330000, 70),
    ('Puka Shell Beach', 'Yapak', 'puka, puka beach', 12.0017000, 121.9150000, 80),
    ('Diniwid Beach', 'Balabag', 'diniwid', 11.9850000, 121.9135000, 90),
    ('Ilig-Iligan Beach', 'Yapak', 'ilig iligan, iligan', 11.9960000, 121.9300000, 100),
    ('Mount Luho View Deck', 'Balabag', 'luho, view deck, viewpoint', 11.9720000, 121.9370000, 110),
    ('Caticlan Jetty Port (mainland)', 'Caticlan, Malay', 'caticlan, jetty, port', 11.9290000, 121.9530000, 120),
    ('Caticlan Airport (Godofredo P. Ramos)', 'Caticlan, Malay', 'airport, mph, caticlan', 11.9245000, 121.9540000, 130);
