-- PCEC Community Platform — MySQL 5.7+/MariaDB 10.3+ schema
-- Usage: mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS pcec_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pcec_app;
SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS donations, event_registrations, payments, giving_projects, event_schedule, event_speakers, settings,
  messages, conversation_members, conversations, notifications, prayer_responses,
  prayer_requests, resources, event_rsvps, events, bookmarks, comments, post_likes, posts,
  follows, remember_tokens, password_resets, users, churches;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE churches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  denomination VARCHAR(120) NULL,
  city VARCHAR(100) NULL,
  region VARCHAR(100) NULL,
  address VARCHAR(255) NULL,
  pastor VARCHAR(120) NULL,
  contact VARCHAR(120) NULL,
  description TEXT NULL,
  photo VARCHAR(255) NULL,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  username VARCHAR(40) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  church_id INT UNSIGNED NULL,
  title VARCHAR(60) NULL,            -- e.g. Rev., Ptr., Bro., Sis.
  role ENUM('member','leader','admin') NOT NULL DEFAULT 'member',
  bio VARCHAR(500) NULL,
  avatar VARCHAR(255) NULL,
  last_seen DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE remember_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE follows (
  follower_id INT UNSIGNED NOT NULL,
  following_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (follower_id, following_id),
  FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NULL,
  media_path VARCHAR(255) NULL,
  media_type ENUM('image','video','file') NULL,
  media_name VARCHAR(255) NULL,
  share_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (created_at)
) ENGINE=InnoDB;

CREATE TABLE post_likes (
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, user_id),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE bookmarks (
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, user_id),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'National',
  description TEXT NULL,
  location VARCHAR(180) NULL,
  venue VARCHAR(180) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  image VARCHAR(255) NULL,
  fee INT UNSIGNED NOT NULL DEFAULT 0,          -- registration fee in centavos (0 = free)
  fee_note VARCHAR(255) NULL,
  highlights VARCHAR(255) NULL,                 -- comma list, e.g. Worship,Prayer,Networking
  capacity INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (starts_at)
) ENGINE=InnoDB;

CREATE TABLE event_rsvps (
  event_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (event_id, user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE resources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'General',
  description TEXT NULL,
  file_path VARCHAR(255) NULL,
  file_name VARCHAR(255) NULL,
  url VARCHAR(255) NULL,
  downloads INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE prayer_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  is_answered TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE prayer_responses (
  prayer_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (prayer_id, user_id),
  FOREIGN KEY (prayer_id) REFERENCES prayer_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NULL,
  type VARCHAR(30) NOT NULL,
  message VARCHAR(255) NOT NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (user_id, is_read)
) ENGINE=InnoDB;

CREATE TABLE conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE conversation_members (
  conversation_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  last_read_id INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (conversation_id, user_id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Giving, payments (PayMongo) and event registration
-- ---------------------------------------------------------------
CREATE TABLE settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE event_speakers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  role VARCHAR(160) NULL,
  photo VARCHAR(255) NULL,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_schedule (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  time_label VARCHAR(40) NOT NULL,
  title VARCHAR(180) NOT NULL,
  description VARCHAR(500) NULL,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE giving_projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  goal_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- centavos
  image VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL UNIQUE,         -- our reference, e.g. PCEC-260101-AB12CD
  user_id INT UNSIGNED NULL,
  purpose ENUM('donation','event') NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount INT UNSIGNED NOT NULL,                  -- centavos
  method VARCHAR(20) NOT NULL DEFAULT 'qrph',    -- qrph | card | ewallet | bank
  status ENUM('pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  livemode TINYINT(1) NOT NULL DEFAULT 0,
  intent_id VARCHAR(64) NULL,                    -- PayMongo payment intent (QR Ph)
  checkout_id VARCHAR(64) NULL,                  -- PayMongo checkout session (card / e-wallet)
  provider_payment_id VARCHAR(64) NULL,          -- PayMongo pay_... id once paid
  qr_image MEDIUMTEXT NULL,                      -- base64 data URI from PayMongo
  qr_expires_at DATETIME NULL,
  payer_reference VARCHAR(100) NULL,             -- bank transfer reference given by the donor
  admin_note VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  checked_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (intent_id), INDEX (checkout_id), INDEX (status)
) ENGINE=InnoDB;

CREATE TABLE donations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL UNIQUE,
  user_id INT UNSIGNED NULL,
  gift_type ENUM('one_time','recurring','project') NOT NULL DEFAULT 'one_time',
  frequency ENUM('monthly','quarterly','yearly') NULL,
  fund VARCHAR(80) NOT NULL DEFAULT 'General Fund',
  project_id INT UNSIGNED NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  message VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (project_id) REFERENCES giving_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE event_registrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  payment_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (event_id, user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Sample data. Every demo account's password is: password123
-- ---------------------------------------------------------------
INSERT INTO churches (name, denomination, city, region, address, pastor, contact, description) VALUES
('Grace Bible Church', 'Baptist', 'Quezon City', 'NCR', '12 Mapagmahal St., Diliman', 'Rev. Daniel Santos', '0917 000 1111', 'A Bible-centered community reaching families in Quezon City.'),
('Living Hope Fellowship', 'Pentecostal', 'Cebu City', 'Central Visayas', 'Osmeña Blvd.', 'Ptr. Maria Reyes', '0918 000 2222', 'Proclaiming living hope across the Visayas.'),
('Cornerstone Christian Church', 'Non-denominational', 'Davao City', 'Davao Region', 'J.P. Laurel Ave.', 'Ptr. Joseph Lim', '0919 000 3333', 'Building lives on Christ, the cornerstone.'),
('Victory Alliance Church', 'Christian & Missionary Alliance', 'Baguio City', 'CAR', 'Session Road', 'Rev. Ana Bautista', '0920 000 4444', 'Making disciples in the Cordilleras.'),
('New Life Methodist Church', 'Methodist', 'Iloilo City', 'Western Visayas', 'General Luna St.', 'Rev. Paolo Cruz', '0921 000 5555', 'Serving Iloilo with grace and truth.');

INSERT INTO users (first_name, last_name, email, username, password_hash, church_id, title, role, bio) VALUES
('John', 'Tan', 'john@pcec.test', 'johntan', '$2y$10$b0W6UXAMVK5h2wxLwyp9I.oLFWtpRZCWLF9vf8Nolhdu2CYxaTeGq', 1, 'Bro.', 'admin', 'Serving the Lord through media ministry.'),
('Daniel', 'Santos', 'daniel@pcec.test', 'dsantos', '$2y$10$b0W6UXAMVK5h2wxLwyp9I.oLFWtpRZCWLF9vf8Nolhdu2CYxaTeGq', 1, 'Rev.', 'leader', 'Senior Pastor, Grace Bible Church.'),
('Maria', 'Reyes', 'maria@pcec.test', 'mreyes', '$2y$10$b0W6UXAMVK5h2wxLwyp9I.oLFWtpRZCWLF9vf8Nolhdu2CYxaTeGq', 2, 'Ptr.', 'leader', 'Lead Pastor, Living Hope Fellowship.'),
('Joseph', 'Lim', 'joseph@pcec.test', 'jlim', '$2y$10$b0W6UXAMVK5h2wxLwyp9I.oLFWtpRZCWLF9vf8Nolhdu2CYxaTeGq', 3, 'Ptr.', 'leader', 'Youth & discipleship ministry.'),
('Grace', 'Villanueva', 'grace@pcec.test', 'gracev', '$2y$10$b0W6UXAMVK5h2wxLwyp9I.oLFWtpRZCWLF9vf8Nolhdu2CYxaTeGq', 4, 'Sis.', 'member', 'Worship leader and Sunday school teacher.');

INSERT INTO follows (follower_id, following_id) VALUES (1,2),(1,3),(2,1),(3,1),(4,1),(5,2);

INSERT INTO posts (user_id, body, share_count, created_at) VALUES
(2, 'Let''s continue to pray for our churches and communities. God is moving in amazing ways!', 12, NOW() - INTERVAL 2 HOUR),
(3, 'Thank you to everyone who joined our Visayas Leaders Fellowship last weekend. "Behold, how good and pleasant it is when brothers dwell in unity!" — Psalm 133:1', 5, NOW() - INTERVAL 1 DAY),
(4, 'Youth camp registration is now open! Invite the young people in your church.', 3, NOW() - INTERVAL 2 DAY);

INSERT INTO post_likes (post_id, user_id) VALUES (1,1),(1,3),(1,4),(1,5),(2,1),(2,2),(3,5);
INSERT INTO comments (post_id, user_id, body) VALUES
(1, 3, 'Amen! Praying with you, Pastor.'),
(1, 5, 'God is faithful!'),
(2, 1, 'It was a blessed time. Salamat po!');

INSERT INTO events (user_id, title, category, description, location, venue, starts_at, ends_at, fee, fee_note, highlights) VALUES
(1, 'PCEC National Prayer Summit', 'National', 'A united time of prayer, worship, and vision for a greater Philippines. Join church leaders, pastors, and believers from across the nation as we seek God together.', 'Manila, Philippines', 'PCEC Conference Center', DATE_ADD(CURDATE(), INTERVAL 36 DAY) + INTERVAL 9 HOUR, DATE_ADD(CURDATE(), INTERVAL 36 DAY) + INTERVAL 17 HOUR, 50000, 'Includes event kit, lunch, and materials.', 'Worship,Prayer,Networking,Breakout Sessions'),
(3, 'Visayas Church Leaders Conference', 'Regional', 'Equipping pastors and ministry leaders across the Visayas.', 'Cebu City', 'Waterfront Convention Hall', DATE_ADD(CURDATE(), INTERVAL 50 DAY) + INTERVAL 8 HOUR, DATE_ADD(CURDATE(), INTERVAL 50 DAY) + INTERVAL 16 HOUR, 0, NULL, 'Worship,Training,Networking'),
(4, 'Mindanao Youth Camp', 'Youth', 'Three days of worship, the Word and fellowship for young people.', 'Davao City', 'Eden Nature Park', DATE_ADD(CURDATE(), INTERVAL 64 DAY) + INTERVAL 7 HOUR, DATE_ADD(CURDATE(), INTERVAL 66 DAY) + INTERVAL 17 HOUR, 150000, 'Includes 3 days of meals, lodging and camp shirt.', 'Worship,Prayer,Outdoor,Breakout Sessions');

INSERT INTO event_rsvps (event_id, user_id) VALUES (1,2),(1,3),(1,5),(2,3);

INSERT INTO resources (user_id, title, category, description, url) VALUES
(1, 'PCEC Statement of Faith', 'Documents', 'The doctrinal statement shared by PCEC member churches.', 'https://pcec.org.ph'),
(2, 'Small Group Discipleship Guide', 'Discipleship', '12-week guide for small group leaders.', NULL),
(3, 'Church Prayer Calendar 2026', 'Prayer', 'Daily prayer points for the nation and member churches.', NULL);

INSERT INTO prayer_requests (user_id, title, body, is_anonymous, created_at) VALUES
(5, 'Healing for my mother', 'Please pray for my mother''s recovery after her surgery.', 0, NOW() - INTERVAL 5 HOUR),
(4, 'Typhoon relief in Eastern Samar', 'Pray for the families affected and for our relief teams.', 0, NOW() - INTERVAL 1 DAY),
(2, 'Wisdom for church leaders', 'Pray for wisdom and unity among our church boards.', 1, NOW() - INTERVAL 3 DAY);

INSERT INTO prayer_responses (prayer_id, user_id) VALUES (1,1),(1,2),(1,3),(2,1),(2,5);

INSERT INTO notifications (user_id, actor_id, type, message, link, created_at) VALUES
(1, 2, 'follow', 'Rev. Daniel Santos started following you.', 'profile.php?u=dsantos', NOW() - INTERVAL 3 HOUR),
(1, 3, 'like', 'Ptr. Maria Reyes liked your comment.', 'post.php?id=2', NOW() - INTERVAL 2 HOUR),
(1, 2, 'message', 'Rev. Daniel Santos sent you a message.', 'chat.php?c=1', NOW() - INTERVAL 1 HOUR);

INSERT INTO conversations (id) VALUES (1), (2);
INSERT INTO conversation_members (conversation_id, user_id, last_read_id) VALUES (1,1,0),(1,2,3),(2,1,0),(2,3,2);
INSERT INTO messages (conversation_id, user_id, body, created_at) VALUES
(1, 2, 'Hi John! Are you joining the Prayer Summit?', NOW() - INTERVAL 70 MINUTE),
(1, 1, 'Yes Pastor, I will be there with our media team.', NOW() - INTERVAL 65 MINUTE),
(1, 2, 'Praise God! See you there.', NOW() - INTERVAL 60 MINUTE),
(2, 3, 'Kumusta! Can you share the conference poster?', NOW() - INTERVAL 30 MINUTE),
(2, 3, 'Salamat po in advance!', NOW() - INTERVAL 29 MINUTE);

UPDATE churches SET is_featured = 1 WHERE id IN (1, 2, 3, 4);

INSERT INTO event_speakers (event_id, name, role, sort) VALUES
(1, 'Bishop Noel Pantoja', 'National Director, PCEC', 1),
(1, 'Rev. Daniel Santos', 'Senior Pastor, Grace Bible Church', 2),
(1, 'Ptr. Maria Reyes', 'Lead Pastor, Living Hope Fellowship', 3);

INSERT INTO event_schedule (event_id, time_label, title, description, sort) VALUES
(1, '9:00 AM', 'Registration & Worship', 'Doors open, kits and worship set.', 1),
(1, '10:00 AM', 'Opening Message', 'Praying for the nation.', 2),
(1, '12:00 PM', 'Lunch & Fellowship', NULL, 3),
(1, '1:30 PM', 'Breakout Sessions', 'Prayer for families, youth, government and the church.', 4),
(1, '4:00 PM', 'United Prayer & Commissioning', NULL, 5);

INSERT INTO giving_projects (title, description, goal_amount, sort) VALUES
('Typhoon Relief — Eastern Visayas', 'Food packs, clean water and rebuilding materials for families affected by recent typhoons.', 50000000, 1),
('Church Planting in Mindanao', 'Support new congregations in unreached communities across Mindanao.', 100000000, 2),
('Pastors Training Scholarship', 'Theological training scholarships for rural pastors.', 30000000, 3);

INSERT INTO settings (k, v) VALUES
('giving_payee', 'Philippine Council of Evangelical Churches'),
('giving_funds', 'General Fund,Missions,Disaster Relief,Church Planting'),
('giving_amounts', '100,500,1000,2500,5000'),
('paymongo_mode', 'test'),
('paymongo_methods', 'qrph,card,gcash,paymaya,grab_pay'),
('bank_enabled', '1'),
('bank_name', 'BDO Unibank'),
('bank_account_name', 'Philippine Council of Evangelical Churches'),
('bank_account_number', '0000-0000-0000'),
('bank_instructions', 'Use your PCEC reference number as the transfer note, then submit your bank reference below.');
