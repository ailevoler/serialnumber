-- UPass joins the marketplace (book passes and pay with Credits), and listings run by Ultimate App can be paid with
-- Credits too (paid to Ultimate App). Import once after database/marketplace_migration.sql. Safe to run again.
ALTER TABLE mk_listings MODIFY service ENUM('upass','ugo','ulocal','ueat') NOT NULL;
ALTER TABLE mk_bookings MODIFY service ENUM('upass','ugo','ulocal') NOT NULL;

-- Starter UPass listings run by Ultimate App. Edit prices or remove them in Admin › UGo · ULocal · UEat › Listings.
INSERT INTO mk_listings (service, merchant_id, category, title, summary, description, includes_text, price_centavos, price_unit, duration_minutes, time_slots, capacity, location, meeting_point, status, featured, badge, sort_order)
SELECT * FROM (
    SELECT 'upass' s, NULL m, 'Airport Transfer' c, 'Island Arrival Pass' t, 'Caticlan airport to your Boracay hotel' su, 'Van or tricycle from Caticlan airport to the jetty port, boat to Cagban, then land transfer to your hotel. Terminal and environmental fees included.' d, 'Airport to jetty transfer\nBoat to Boracay\nTerminal and environmental fees\nHotel drop-off' i, 95000 p, 'person' u, 90 dm, NULL ts, NULL cap, 'Caticlan Airport' l, 'Arrival hall, look for the Ultimate App sign' mp, 'active' st, 1 f, 'Popular' b, 10 so
    UNION ALL SELECT 'upass', NULL, 'Airport Transfer', 'Island Departure Pass', 'Hotel to Caticlan airport', 'Hotel pick-up, land transfer to Cagban port, boat to Caticlan and transfer to the airport.', 'Hotel pick-up\nBoat to Caticlan\nTerminal fee', 95000, 'person', 90, NULL, NULL, 'Your hotel', 'Hotel lobby', 'active', 0, NULL, 20
    UNION ALL SELECT 'upass', NULL, 'Day Pass', 'Island Day Pass', 'Transport and one activity in one pass', 'Unlimited e-trike hops for the day plus one activity of your choice.', 'Day transport\nOne activity', 145000, 'person', 480, '08:00', 30, 'Boracay', 'Hotel pick-up', 'active', 1, NULL, 30
    UNION ALL SELECT 'upass', NULL, 'Activity Bundle', 'Explore Pass', 'Island hopping, land tour and sunset sail', 'Three of Boracay''s favourites in one pass, on the days you choose.', 'Island hopping\nLand tour\nSunset paraw sail', 220000, 'person', NULL, NULL, NULL, 'Boracay', NULL, 'active', 0, 'Best value', 40
) seed
WHERE NOT EXISTS (SELECT 1 FROM mk_listings WHERE service = 'upass');
