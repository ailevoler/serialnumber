-- PCEC Connect — MySQL 5.7+/MariaDB 10.3+ schema (version 3)
-- Fresh install:  mysql -u root -p < database/schema.sql
-- Existing installs are upgraded automatically on the first page load (includes/migrate.php).

CREATE DATABASE IF NOT EXISTS pcec_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pcec_app;
SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS donations, event_registrations, payments, giving_projects, event_schedule, event_speakers, settings,
  messages, conversation_members, conversations, notifications, prayer_responses, prayer_requests, resources,
  event_rsvps, events, bookmarks, comments, post_likes, post_reactions, post_reports, user_blocks, posts, follows,
  remember_tokens, password_resets, email_tokens, announcements, ndc_posts, media, contact_messages, users, churches;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  username VARCHAR(40) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  title VARCHAR(60) NULL,                      -- e.g. Rev., Ptr., Bro., Sis.
  role ENUM('member','leader','admin') NOT NULL DEFAULT 'member',
  status ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',  -- pending = email not confirmed yet
  phone VARCHAR(30) NULL,                      -- E.164, e.g. +639171234567
  phone_country CHAR(2) NOT NULL DEFAULT 'PH',
  church_name VARCHAR(160) NULL,
  address VARCHAR(400) NULL,
  bio VARCHAR(500) NULL,
  avatar VARCHAR(255) NULL,
  membership_renewal_date DATE NULL,
  email_verified_at DATETIME NULL,
  terms_accepted_at DATETIME NULL,
  reviewed_at DATETIME NULL,                   -- admin looked at a new member ("New Member" badge goes away)
  reviewed_by INT UNSIGNED NULL,
  last_seen DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (status)
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

CREATE TABLE email_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(20) NOT NULL DEFAULT 'confirm',
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Community feed
-- ---------------------------------------------------------------
CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NULL,
  media_path VARCHAR(255) NULL,                -- photo only
  media_type ENUM('image','video','file') NULL,
  media_name VARCHAR(255) NULL,
  share_count INT UNSIGNED NOT NULL DEFAULT 0,
  hidden_at DATETIME NULL,                     -- hidden by an admin
  hidden_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (created_at)
) ENGINE=InnoDB;

CREATE TABLE post_reactions (
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  kind ENUM('love','pray','amen') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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

CREATE TABLE post_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  reporter_id INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by INT UNSIGNED NULL,
  UNIQUE KEY (post_id, reporter_id),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_blocks (
  blocker_id INT UNSIGNED NOT NULL,
  blocked_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_id, blocked_id),
  FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Content managed by admins
-- ---------------------------------------------------------------
CREATE TABLE announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  body TEXT NULL,
  image VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at)
) ENGINE=InnoDB;

CREATE TABLE ndc_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  post_type ENUM('event','picture','promotion') NOT NULL DEFAULT 'promotion',
  content TEXT NULL,
  image VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
  highlights VARCHAR(255) NULL,
  capacity INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (starts_at)
) ENGINE=InnoDB;

CREATE TABLE event_rsvps (                      -- confirmed attendees
  event_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (event_id, user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  caption VARCHAR(300) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(200) NOT NULL,
  subject VARCHAR(160) NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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

-- ---------------------------------------------------------------
-- Chat
-- ---------------------------------------------------------------
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
-- Settings, payments (PayMongo QR Ph) and event registration
-- ---------------------------------------------------------------
CREATE TABLE settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL UNIQUE,         -- e.g. PCEC-261010-AB12CD
  user_id INT UNSIGNED NULL,
  purpose ENUM('donation','event') NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount INT UNSIGNED NOT NULL,                  -- centavos, total charged (gift + fee_amount)
  fee_amount INT UNSIGNED NOT NULL DEFAULT 0,    -- processing fee (MDR) added on top of the gift
  method VARCHAR(20) NOT NULL DEFAULT 'qrph',
  status ENUM('pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  livemode TINYINT(1) NOT NULL DEFAULT 0,
  intent_id VARCHAR(64) NULL,
  checkout_id VARCHAR(64) NULL,
  provider_payment_id VARCHAR(64) NULL,
  qr_image MEDIUMTEXT NULL,
  qr_expires_at DATETIME NULL,
  payer_reference VARCHAR(100) NULL,
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
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  message VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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
INSERT INTO users (first_name, last_name, email, username, password_hash, title, role, status, phone, church_name, bio, email_verified_at, terms_accepted_at, reviewed_at, created_at) VALUES
('John', 'Tan', 'john@pcec.test', 'johntan', '$2y$10$DASehWRfzXNsidSjwyGg/OkTpop2CcwpmhXJ0iOrnGKa1tEqlm2za', 'Bro.', 'admin', 'approved', '+639171110001', 'Grace Bible Church', 'Serving the Lord through media ministry.', NOW() - INTERVAL 90 DAY, NOW() - INTERVAL 90 DAY, NOW() - INTERVAL 89 DAY, NOW() - INTERVAL 90 DAY),
('Daniel', 'Santos', 'daniel@pcec.test', 'dsantos', '$2y$10$DASehWRfzXNsidSjwyGg/OkTpop2CcwpmhXJ0iOrnGKa1tEqlm2za', 'Rev.', 'member', 'approved', '+639171110002', 'Grace Bible Church', 'Senior Pastor, Grace Bible Church.', NOW() - INTERVAL 80 DAY, NOW() - INTERVAL 80 DAY, NOW() - INTERVAL 79 DAY, NOW() - INTERVAL 80 DAY),
('Maria', 'Reyes', 'maria@pcec.test', 'mreyes', '$2y$10$DASehWRfzXNsidSjwyGg/OkTpop2CcwpmhXJ0iOrnGKa1tEqlm2za', 'Ptr.', 'member', 'approved', '+639171110003', 'Living Hope Fellowship', 'Lead Pastor, Living Hope Fellowship.', NOW() - INTERVAL 70 DAY, NOW() - INTERVAL 70 DAY, NOW() - INTERVAL 69 DAY, NOW() - INTERVAL 70 DAY),
('Joseph', 'Lim', 'joseph@pcec.test', 'jlim', '$2y$10$DASehWRfzXNsidSjwyGg/OkTpop2CcwpmhXJ0iOrnGKa1tEqlm2za', 'Ptr.', 'member', 'approved', '+639171110004', 'Cornerstone Christian Church', 'Youth & discipleship ministry.', NOW() - INTERVAL 60 DAY, NOW() - INTERVAL 60 DAY, NULL, NOW() - INTERVAL 60 DAY),
('Grace', 'Villanueva', 'grace@pcec.test', 'gracev', '$2y$10$DASehWRfzXNsidSjwyGg/OkTpop2CcwpmhXJ0iOrnGKa1tEqlm2za', 'Sis.', 'member', 'approved', '+639171110005', 'Victory Alliance Church', 'Worship leader and Sunday school teacher.', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY, NULL, NOW() - INTERVAL 3 DAY),
('Paolo', 'Cruz', 'paolo@pcec.test', 'pcruz', '$2y$10$DASehWRfzXNsidSjwyGg/OkTpop2CcwpmhXJ0iOrnGKa1tEqlm2za', NULL, 'member', 'pending', '+639171110006', NULL, NULL, NULL, NOW() - INTERVAL 1 DAY, NULL, NOW() - INTERVAL 1 DAY);

UPDATE users SET membership_renewal_date = CURDATE() + INTERVAL 45 DAY WHERE username IN ('johntan', 'dsantos');

INSERT INTO posts (user_id, body, created_at) VALUES
(2, 'Let''s continue to pray for our churches and communities. God is moving in amazing ways!', NOW() - INTERVAL 2 HOUR),
(3, 'Thank you to everyone who joined our Visayas Leaders Fellowship last weekend. "Behold, how good and pleasant it is when brothers dwell in unity!" — Psalm 133:1', NOW() - INTERVAL 1 DAY),
(4, 'Youth camp registration is now open! Invite the young people in your church.', NOW() - INTERVAL 2 DAY);

INSERT INTO post_reactions (post_id, user_id, kind) VALUES (1,1,'pray'),(1,3,'amen'),(1,4,'love'),(1,5,'pray'),(2,1,'love'),(2,2,'amen'),(3,5,'love');
INSERT INTO comments (post_id, user_id, body) VALUES
(1, 3, 'Amen! Praying with you, Pastor.'),
(1, 5, 'God is faithful!'),
(2, 1, 'It was a blessed time. Salamat po!');

INSERT INTO announcements (title, body, created_by, created_at) VALUES
('PCEC National Prayer Summit registration is open', 'Join church leaders, pastors and believers from across the nation for a day of united prayer. Register in the Events tab.\n\nSeats are limited, so please register early.', 1, NOW() - INTERVAL 5 HOUR),
('Typhoon relief: how your church can help', 'PCEC is coordinating relief for families affected by the recent typhoon in Eastern Samar. Donations can be made through the Donate tab using QR Ph.', 1, NOW() - INTERVAL 2 DAY),
('Welcome to PCEC Connect', 'PCEC Connect brings news, events, giving and fellowship for our member churches together in one app.', 1, NOW() - INTERVAL 10 DAY);

INSERT INTO ndc_posts (title, post_type, content, created_by, created_at) VALUES
('NDC Regional Assembly — Luzon', 'event', 'All NDC members in Luzon are invited to the regional assembly. Details to follow.', 1, NOW() - INTERVAL 1 DAY),
('Pastors Fellowship photos', 'picture', 'Thank you to everyone who joined the Pastors Fellowship in Cebu.', 1, NOW() - INTERVAL 4 DAY),
('Early-bird registration for the Annual Convention', 'promotion', 'NDC members get priority registration for the PCEC Annual Convention until the end of the month.', 1, NOW() - INTERVAL 8 DAY);

INSERT INTO events (user_id, title, category, description, location, venue, starts_at, ends_at, fee, fee_note, highlights) VALUES
(1, 'PCEC National Prayer Summit', 'National', 'A united time of prayer, worship, and vision for a greater Philippines. Join church leaders, pastors, and believers from across the nation as we seek God together.', 'Manila, Philippines', 'PCEC Conference Center', DATE_ADD(CURDATE(), INTERVAL 36 DAY) + INTERVAL 9 HOUR, DATE_ADD(CURDATE(), INTERVAL 36 DAY) + INTERVAL 17 HOUR, 50000, 'Includes event kit, lunch, and materials.', 'Worship,Prayer,Networking,Breakout Sessions'),
(1, 'Visayas Church Leaders Conference', 'Regional', 'Equipping pastors and ministry leaders across the Visayas.', 'Cebu City', 'Waterfront Convention Hall', DATE_ADD(CURDATE(), INTERVAL 50 DAY) + INTERVAL 8 HOUR, DATE_ADD(CURDATE(), INTERVAL 50 DAY) + INTERVAL 16 HOUR, 0, NULL, 'Worship,Training,Networking'),
(1, 'Mindanao Youth Camp', 'Youth', 'Three days of worship, the Word and fellowship for young people.', 'Davao City', 'Eden Nature Park', DATE_ADD(CURDATE(), INTERVAL 64 DAY) + INTERVAL 7 HOUR, DATE_ADD(CURDATE(), INTERVAL 66 DAY) + INTERVAL 17 HOUR, 150000, 'Includes 3 days of meals, lodging and camp shirt.', 'Worship,Prayer,Outdoor,Breakout Sessions'),
(1, 'Easter Sunrise Service', 'National', 'A joint sunrise service of PCEC member churches.', 'Quezon City', 'Quezon Memorial Circle', DATE_SUB(CURDATE(), INTERVAL 20 DAY) + INTERVAL 5 HOUR, DATE_SUB(CURDATE(), INTERVAL 20 DAY) + INTERVAL 7 HOUR, 0, NULL, 'Worship,Prayer');

INSERT INTO event_rsvps (event_id, user_id) VALUES (2,2),(2,3),(4,2);
INSERT INTO event_registrations (event_id, user_id, status) VALUES (2,2,'confirmed'),(2,3,'confirmed'),(4,2,'confirmed');

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

INSERT INTO contact_messages (user_id, name, email, subject, message, created_at) VALUES
(4, 'Joseph Lim', 'joseph@pcec.test', 'Membership renewal', 'Hi, how can our church renew its PCEC membership for next year?', NOW() - INTERVAL 6 HOUR);

INSERT INTO notifications (user_id, actor_id, type, message, link, created_at) VALUES
(1, NULL, 'announcement', 'New announcement: PCEC National Prayer Summit registration is open', 'news.php?id=1', NOW() - INTERVAL 5 HOUR),
(1, 3, 'comment', 'Ptr. Maria Reyes commented on a post you follow.', 'community_post.php?id=1', NOW() - INTERVAL 3 HOUR),
(1, 2, 'message', 'Rev. Daniel Santos sent you a message.', 'chat.php?c=1', NOW() - INTERVAL 1 HOUR);

INSERT INTO conversations (id) VALUES (1), (2);
INSERT INTO conversation_members (conversation_id, user_id, last_read_id) VALUES (1,1,0),(1,2,3),(2,1,0),(2,3,2);
INSERT INTO messages (conversation_id, user_id, body, created_at) VALUES
(1, 2, 'Hi John! Are you joining the Prayer Summit?', NOW() - INTERVAL 70 MINUTE),
(1, 1, 'Yes Pastor, I will be there with our media team.', NOW() - INTERVAL 65 MINUTE),
(1, 2, 'Praise God! See you there.', NOW() - INTERVAL 60 MINUTE),
(2, 3, 'Kumusta! Can you share the conference poster?', NOW() - INTERVAL 30 MINUTE),
(2, 3, 'Salamat po in advance!', NOW() - INTERVAL 29 MINUTE);

INSERT INTO settings (k, v) VALUES
('schema_version', '3'),
('about_org_name', 'Philippine Council of Evangelical Churches'),
('about_text', ''),
('about_established_year', '1965'),
('about_office_location', 'Quezon City, Philippines'),
('about_office_phone', '(02) 8000 0000'),
('about_office_email', 'office@pcec.test'),
('about_website', 'https://pcec.org.ph'),
('giving_payee', 'Philippine Council of Evangelical Churches'),
('giving_funds', 'General Fund'),
('giving_amounts', '100,250,500,1000,2500'),
('paymongo_mode', 'test'),
('paymongo_methods', 'qrph'),
('fee_percent', '1.5'),
('fee_fixed', '0'),
('fee_cover_donations', 'always'),
('fee_cover_events', '1'),
('bank_enabled', '0'),
('mail_from_name', 'PCEC Connect');
