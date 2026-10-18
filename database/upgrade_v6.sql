-- XGEN LIFE Intl v6: corporate website with CMS and Content Manager accounts.
-- Fresh installs: already included at the end of schema.sql.
SET NAMES utf8mb4;

ALTER TABLE users MODIFY role ENUM('member','admin','cms') NOT NULL DEFAULT 'member';

CREATE TABLE IF NOT EXISTS cms_content (
  ckey VARCHAR(60) PRIMARY KEY,
  cvalue TEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cms_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(20) NOT NULL,
  title VARCHAR(160) NOT NULL,
  subtitle VARCHAR(160) NULL,
  body TEXT NULL,
  image VARCHAR(255) NULL,
  icon VARCHAR(30) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('published','draft') NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_type (type, status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cms_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  alt VARCHAR(160) NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (skey, svalue) VALUES ('home_mode', 'landing');

-- Starter content (edit in Admin → Website CMS → Lists & Testimonials)
INSERT INTO cms_items (type, title, subtitle, body, icon, sort_order, status)
SELECT * FROM (
  SELECT 'stat' t, '6' ti, 'Ways to earn' s, NULL b, NULL i, 1 o, 'published' st UNION ALL
  SELECT 'stat', '24/7', 'Online member portal', NULL, NULL, 2, 'published' UNION ALL
  SELECT 'stat', 'QR Ph', 'Fast, secure payments', NULL, NULL, 3, 'published' UNION ALL
  SELECT 'stat', '100%', 'Automated commissions', NULL, NULL, 4, 'published' UNION ALL
  SELECT 'feature', 'Quality products', NULL, 'Carefully selected wellness products that members are proud to use and share.', 'shield', 1, 'published' UNION ALL
  SELECT 'feature', 'Transparent earnings', NULL, 'Every bonus is computed automatically and visible in your dashboard in real time.', 'trending', 2, 'published' UNION ALL
  SELECT 'feature', 'Fast payouts', NULL, 'Withdraw your e-wallet balance to GCash, Maya or your bank account.', 'wallet', 3, 'published' UNION ALL
  SELECT 'feature', 'Real support', NULL, 'Live chat support, training and a community that helps you grow.', 'users', 4, 'published' UNION ALL
  SELECT 'faq', 'How do I become a member?', NULL, 'Get an activation code from your sponsor or buy one online, then register using your sponsor''s referral link. Your account is activated immediately.', NULL, 1, 'published' UNION ALL
  SELECT 'faq', 'How much does it cost to join?', NULL, 'Choose from our Starter, Business or Elite packages. Each package includes products and binary points for the pairing bonus.', NULL, 2, 'published' UNION ALL
  SELECT 'faq', 'How do I receive my earnings?', NULL, 'All bonuses go to your e-wallet. Request a payout anytime to GCash, Maya or your bank account once you reach the minimum amount.', NULL, 3, 'published' UNION ALL
  SELECT 'faq', 'Can I buy products without joining the business?', NULL, 'Yes. Contact us or a member near you. Members enjoy exclusive member prices on every purchase.', NULL, 4, 'published' UNION ALL
  SELECT 'testimonial', 'Sample Member', 'Replace with a real member story', 'Add real member testimonials here. Sample entries stay in Draft so they never appear on the live website.', NULL, 1, 'draft'
) seed
WHERE NOT EXISTS (SELECT 1 FROM cms_items);
