-- Marketplace for merchants: UGo (activities and tours), ULocal (local services) and UEat (restaurants and menus).
-- Merchants add their own listings in the Merchant Portal; Admin can add listings too and approves what merchants send.
-- Customers book (UGo / ULocal) or order food (UEat) and pay with Credits; the money goes to the merchant's balance
-- (same as a QR payment) and is refunded in full when the merchant declines or the customer cancels in time.
-- Import once in phpMyAdmin after the merchant and UEat migrations. Safe to run again (MariaDB 10.3+).

CREATE TABLE IF NOT EXISTS mk_listings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    service ENUM('ugo','ulocal','ueat') NOT NULL,
    merchant_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = run by Ultimate App (request only, pay on the day)',
    category VARCHAR(40) NOT NULL COMMENT 'UGo/ULocal category, or the UEat menu section',
    title VARCHAR(120) NOT NULL,
    summary VARCHAR(200) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    includes_text VARCHAR(500) DEFAULT NULL COMMENT 'What is included, one per line',
    price_centavos INT UNSIGNED NOT NULL,
    price_unit ENUM('person','group','hour','session','item','load','day') NOT NULL DEFAULT 'person',
    duration_minutes SMALLINT UNSIGNED DEFAULT NULL,
    time_slots VARCHAR(200) DEFAULT NULL COMMENT 'Start times like 08:00,13:00 (UGo). Empty = customer picks a time',
    capacity SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Max guests per date and time (UGo)',
    min_qty SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    max_qty SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    location VARCHAR(160) DEFAULT NULL,
    meeting_point VARCHAR(200) DEFAULT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    cancel_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24 COMMENT 'Free cancellation until this many hours before',
    instant_confirm TINYINT(1) NOT NULL DEFAULT 0,
    photo VARCHAR(40) DEFAULT NULL,
    badge VARCHAR(30) DEFAULT NULL COMMENT 'e.g. Best seller, Popular, New',
    featured TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    status ENUM('pending','active','paused','rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(255) DEFAULT NULL,
    created_by_admin INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY mk_browse (service, status, category, sort_order),
    KEY mk_merchant (merchant_id, service),
    CONSTRAINT mk_listings_merchant_fk FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UEat restaurant profile of a merchant (one per merchant).
CREATE TABLE IF NOT EXISTS mk_stores (
    merchant_id INT UNSIGNED NOT NULL PRIMARY KEY,
    display_name VARCHAR(120) NOT NULL,
    cuisine VARCHAR(60) NOT NULL,
    tagline VARCHAR(160) DEFAULT NULL,
    cover_photo VARCHAR(40) DEFAULT NULL,
    prep_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    delivery_fee_centavos INT UNSIGNED NOT NULL DEFAULT 4900,
    min_order_centavos INT UNSIGNED NOT NULL DEFAULT 0,
    offers_delivery TINYINT(1) NOT NULL DEFAULT 1,
    offers_pickup TINYINT(1) NOT NULL DEFAULT 1,
    opens_at TIME DEFAULT '08:00:00',
    closes_at TIME DEFAULT '22:00:00',
    is_open TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Merchant switch: accepting orders now',
    promo_text VARCHAR(80) DEFAULT NULL COMMENT 'e.g. Free delivery over 500',
    status ENUM('pending','active','paused','rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY mk_store_browse (status, is_open),
    CONSTRAINT mk_stores_merchant_fk FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UGo / ULocal bookings.
CREATE TABLE IF NOT EXISTS mk_bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL,
    request_key CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    listing_id INT UNSIGNED NOT NULL,
    merchant_id INT UNSIGNED DEFAULT NULL,
    service ENUM('ugo','ulocal') NOT NULL,
    title VARCHAR(120) NOT NULL,
    booking_date DATE NOT NULL,
    booking_time TIME DEFAULT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    unit_centavos INT UNSIGNED NOT NULL,
    total_centavos INT UNSIGNED NOT NULL,
    contact_mobile VARCHAR(20) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL COMMENT 'Where the service is done (ULocal)',
    notes VARCHAR(500) DEFAULT NULL,
    pay_method ENUM('credits','on_site') NOT NULL,
    payment_status ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
    payment_ref VARCHAR(40) DEFAULT NULL COMMENT 'merchant_payments.reference',
    status ENUM('pending','confirmed','completed','cancelled','declined') NOT NULL DEFAULT 'pending',
    status_note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY mk_booking_ref (reference),
    UNIQUE KEY mk_booking_key (user_id, request_key),
    KEY mk_booking_slot (listing_id, booking_date, booking_time, status),
    KEY mk_booking_merchant (merchant_id, status, booking_date),
    CONSTRAINT mk_bookings_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT mk_bookings_listing_fk FOREIGN KEY (listing_id) REFERENCES mk_listings (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UEat orders now belong to a merchant restaurant and follow the kitchen's progress.
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS merchant_id INT UNSIGNED DEFAULT NULL AFTER restaurant_id;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS subtotal_centavos INT UNSIGNED DEFAULT NULL AFTER total_credits;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS delivery_fee_centavos INT UNSIGNED NOT NULL DEFAULT 0 AFTER subtotal_centavos;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS payment_ref VARCHAR(40) DEFAULT NULL AFTER payment_status;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS contact_mobile VARCHAR(20) DEFAULT NULL AFTER delivery_address;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS status_note VARCHAR(255) DEFAULT NULL;
ALTER TABLE ueat_orders ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE ueat_orders MODIFY status ENUM('sample','pending','accepted','preparing','on_the_way','ready','completed','declined','cancelled') NOT NULL DEFAULT 'sample';
ALTER TABLE ueat_orders ADD INDEX IF NOT EXISTS ueat_merchant_orders (merchant_id, status, created_at);

-- Referral agents also earn on marketplace bookings.
ALTER TABLE agent_commissions MODIFY source_type ENUM('uride','service_request','ueat','mk_booking') NOT NULL;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('market.credits_checkout', '1'),
    ('market.review_listings', '1'),
    ('market.ueat_min_delivery_fee', '0');

-- Starter UGo / ULocal listings run by Ultimate App (request only, pay on the day). Edit or remove them in Admin › Marketplace.
INSERT INTO mk_listings (service, merchant_id, category, title, summary, description, includes_text, price_centavos, price_unit, duration_minutes, time_slots, capacity, location, meeting_point, status, featured, badge, sort_order)
SELECT * FROM (
    SELECT 'ugo' s, NULL m, 'Land Tour' c, 'Boracay Land Tour' t, 'Mount Luho view deck, Puka Beach, Diniwid and more' su, 'A relaxed half-day tour around the island by e-trike with a local driver-guide. Stops for photos at Mount Luho, Puka Shell Beach, Diniwid and the Willy''s Rock area.' d, 'E-trike with driver-guide\nMount Luho entrance\nBottled water' i, 85000 p, 'person' u, 240 dm, '08:00,13:00' ts, 12 cap, 'Boracay' l, 'Hotel pick-up (Station 1 to 3)' mp, 'active' st, 1 f, 'Popular' b, 10 so
    UNION ALL SELECT 'ugo', NULL, 'Island Hopping', 'Island Hopping', 'Crystal Cove, Crocodile Island and snorkeling', 'Shared boat tour to Crystal Cove and Crocodile Island with a snorkeling stop.', 'Boat and boatman\nSnorkel gear\nEnvironmental fee not included', 140000, 'person', 180, '09:00,13:00', 20, 'Station 3 boat station', 'Station 3 beach front', 'active', 1, 'Best seller', 20
    UNION ALL SELECT 'ugo', NULL, 'Water Sports', 'Parasailing', '15 minutes over White Beach', 'Fly above White Beach with a licensed operator. Tandem or solo.', 'Life vest and harness\nBoat transfer', 220000, 'person', 60, '09:00,11:00,14:00,16:00', 8, 'Station 2', 'Operator booth, Station 2', 'active', 0, NULL, 30
    UNION ALL SELECT 'ugo', NULL, 'Sunset Sail', 'Sunset Paraw Sail', 'Traditional paraw at golden hour', 'Sail along White Beach on a traditional paraw at sunset.', 'Paraw and crew\nLife vest', 110000, 'person', 60, '16:30,17:15', 10, 'White Beach', 'Station 1 beach front', 'active', 1, NULL, 40
    UNION ALL SELECT 'ulocal', NULL, 'Massage & Spa', 'In-room Massage', 'Licensed therapist comes to your hotel', 'Full-body Swedish or Filipino hilot massage in your room.', 'Massage oil\nTowels', 120000, 'hour', 60, NULL, NULL, 'Anywhere in Boracay', NULL, 'active', 1, 'Popular', 10
    UNION ALL SELECT 'ulocal', NULL, 'Photography', 'Vacation Photographer', 'Beach portrait session, edited photos', '1-hour photo session on White Beach or Puka. 30 edited photos within 48 hours.', '30 edited photos\nOnline gallery', 180000, 'session', 60, NULL, NULL, 'White Beach / Puka', NULL, 'active', 0, NULL, 20
    UNION ALL SELECT 'ulocal', NULL, 'Laundry', 'Laundry Pickup', 'Wash, dry and fold, picked up from your hotel', 'Pick-up and delivery to your hotel within 24 hours. Price per 7 kg load.', 'Detergent and softener\nPick-up and delivery', 35000, 'load', NULL, NULL, NULL, 'Anywhere in Boracay', NULL, 'active', 0, NULL, 30
    UNION ALL SELECT 'ulocal', NULL, 'Tour Guide', 'Private Island Guide', 'Local guide for your own itinerary', 'A local guide who knows the island: food spots, hidden beaches and safety tips.', NULL, 90000, 'session', 240, NULL, NULL, 'Boracay', NULL, 'active', 0, NULL, 40
) seed
WHERE NOT EXISTS (SELECT 1 FROM mk_listings);
