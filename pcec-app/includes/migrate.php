<?php
/**
 * Upgrades databases created by an older schema.sql to the current version (3).
 * Runs once on the first page load after an update; every step is safe to repeat.
 * Fresh installs from database/schema.sql are already at version 3 and skip this.
 */
const SCHEMA_VERSION = 3;

function table_exists(string $table): bool
{
    return (bool) q_val('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
}

function column_exists(string $table, string $column): bool
{
    return (bool) q_val('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
}

function add_column(string $table, string $column, string $definition): bool
{
    if (column_exists($table, $column)) return false;
    db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    return true;
}

function db_migrate(): void
{
    try {
        $version = (int) q_val("SELECT v FROM settings WHERE k = 'schema_version'");
    } catch (PDOException $e) {
        $version = 0; // settings table missing: very old install
    }
    if ($version >= SCHEMA_VERSION) return;

    $tables = [
        'settings' => "k VARCHAR(64) PRIMARY KEY, v TEXT NULL",
        'payments' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(32) NOT NULL UNIQUE, user_id INT UNSIGNED NULL,
            purpose ENUM('donation','event') NOT NULL, description VARCHAR(255) NOT NULL, amount INT UNSIGNED NOT NULL,
            fee_amount INT UNSIGNED NOT NULL DEFAULT 0, method VARCHAR(20) NOT NULL DEFAULT 'qrph',
            status ENUM('pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'pending', livemode TINYINT(1) NOT NULL DEFAULT 0,
            intent_id VARCHAR(64) NULL, checkout_id VARCHAR(64) NULL, provider_payment_id VARCHAR(64) NULL, qr_image MEDIUMTEXT NULL,
            qr_expires_at DATETIME NULL, payer_reference VARCHAR(100) NULL, admin_note VARCHAR(255) NULL, paid_at DATETIME NULL,
            checked_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL, INDEX (intent_id), INDEX (checkout_id), INDEX (status)",
        'donations' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payment_id INT UNSIGNED NOT NULL UNIQUE, user_id INT UNSIGNED NULL,
            gift_type ENUM('one_time','recurring','project') NOT NULL DEFAULT 'one_time', frequency ENUM('monthly','quarterly','yearly') NULL,
            fund VARCHAR(80) NOT NULL DEFAULT 'General Fund', is_anonymous TINYINT(1) NOT NULL DEFAULT 0, message VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL",
        'event_registrations' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending', payment_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (event_id, user_id),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL",
        'event_speakers' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL,
            role VARCHAR(160) NULL, photo VARCHAR(255) NULL, sort INT NOT NULL DEFAULT 0, FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE",
        'event_schedule' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL, time_label VARCHAR(40) NOT NULL,
            title VARCHAR(180) NOT NULL, description VARCHAR(500) NULL, sort INT NOT NULL DEFAULT 0, FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE",
        'email_tokens' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, purpose VARCHAR(20) NOT NULL DEFAULT 'confirm',
            token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (token_hash), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE",
        'announcements' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NULL, image VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (created_at)",
        'ndc_posts' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL,
            post_type ENUM('event','picture','promotion') NOT NULL DEFAULT 'promotion', content TEXT NULL, image VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'media' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, path VARCHAR(255) NOT NULL, caption VARCHAR(300) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE",
        'contact_messages' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, name VARCHAR(160) NOT NULL, email VARCHAR(200) NOT NULL,
            subject VARCHAR(160) NULL, message TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL",
        'post_reactions' => "post_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, kind ENUM('love','pray','amen') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (post_id, user_id),
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE",
        'post_reports' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id INT UNSIGNED NOT NULL, reporter_id INT UNSIGNED NOT NULL,
            reason VARCHAR(500) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, resolved_at DATETIME NULL, resolved_by INT UNSIGNED NULL,
            UNIQUE KEY (post_id, reporter_id), FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE",
        'user_blocks' => "blocker_id INT UNSIGNED NOT NULL, blocked_id INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (blocker_id, blocked_id), FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE",
    ];
    foreach ($tables as $name => $ddl) {
        db()->exec("CREATE TABLE IF NOT EXISTS `$name` ($ddl) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Accounts: status + profile fields used by the new sign-up / membership flow.
    $newStatus = add_column('users', 'status', "ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending' AFTER role");
    add_column('users', 'phone', 'VARCHAR(30) NULL AFTER status');
    add_column('users', 'phone_country', "CHAR(2) NOT NULL DEFAULT 'PH' AFTER phone");
    add_column('users', 'church_name', 'VARCHAR(160) NULL AFTER phone_country');
    add_column('users', 'address', 'VARCHAR(400) NULL AFTER church_name');
    add_column('users', 'membership_renewal_date', 'DATE NULL');
    add_column('users', 'email_verified_at', 'DATETIME NULL');
    add_column('users', 'terms_accepted_at', 'DATETIME NULL');
    add_column('users', 'reviewed_at', 'DATETIME NULL');
    add_column('users', 'reviewed_by', 'INT UNSIGNED NULL');
    if ($newStatus) {
        // Everyone who already had an account keeps access.
        q('UPDATE users SET status = ?, email_verified_at = COALESCE(email_verified_at, created_at), reviewed_at = COALESCE(reviewed_at, created_at)', ['approved']);
    }
    if (table_exists('churches') && column_exists('users', 'church_id')) {
        q('UPDATE users u JOIN churches c ON c.id = u.church_id SET u.church_name = c.name WHERE u.church_name IS NULL');
    }

    add_column('events', 'venue', 'VARCHAR(180) NULL AFTER location');
    add_column('events', 'fee', 'INT UNSIGNED NOT NULL DEFAULT 0');
    add_column('events', 'fee_note', 'VARCHAR(255) NULL');
    add_column('events', 'highlights', 'VARCHAR(255) NULL');
    add_column('events', 'capacity', 'INT UNSIGNED NULL');
    add_column('payments', 'fee_amount', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER amount');
    add_column('posts', 'hidden_at', 'DATETIME NULL');
    add_column('posts', 'hidden_by', 'INT UNSIGNED NULL');

    // Old "likes" become love reactions.
    if (table_exists('post_likes')) {
        db()->exec("INSERT IGNORE INTO post_reactions (post_id, user_id, kind) SELECT post_id, user_id, 'love' FROM post_likes");
    }

    $defaults = [
        'about_org_name' => 'Philippine Council of Evangelical Churches', 'about_text' => '', 'about_established_year' => '',
        'about_office_location' => '', 'about_office_phone' => '', 'about_office_email' => '', 'about_website' => '',
        'giving_payee' => 'Philippine Council of Evangelical Churches', 'giving_amounts' => '100,250,500,1000,2500',
        'paymongo_mode' => 'test', 'paymongo_methods' => 'qrph', 'fee_percent' => '1.5', 'fee_fixed' => '0',
        'fee_cover_donations' => 'always', 'fee_cover_events' => '1', 'mail_from_name' => 'PCEC Connect',
    ];
    foreach ($defaults as $k => $v) {
        q('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
    }
    q("INSERT INTO settings (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [(string) SCHEMA_VERSION]);
}
