-- USD ⇄ PHP exchange: live USD/PHP rate, customer USD wallet, buy/sell trades with Admin-set fees.
-- Import once. Safe to run again.
CREATE TABLE IF NOT EXISTS fx_rates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pair CHAR(6) NOT NULL DEFAULT 'USDPHP',
    rate DECIMAL(12,6) NOT NULL COMMENT 'PHP per 1 USD (mid-market)',
    source VARCHAR(40) NOT NULL,
    rate_date DATE DEFAULT NULL COMMENT 'Date the provider published the rate',
    fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY pair_latest (pair, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usd_wallets (
    user_id INT UNSIGNED PRIMARY KEY,
    balance_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT usd_wallets_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fx_trades (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL,
    request_key CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    side ENUM('buy_usd','sell_usd') NOT NULL,
    usd_cents BIGINT UNSIGNED NOT NULL,
    gross_php_centavos BIGINT UNSIGNED NOT NULL COMMENT 'USD amount at the mid rate',
    fee_php_centavos BIGINT UNSIGNED NOT NULL,
    total_php_centavos BIGINT UNSIGNED NOT NULL COMMENT 'Buy: BCash paid. Sell: BCash received.',
    fee_bp INT UNSIGNED NOT NULL,
    rate DECIMAL(12,6) NOT NULL,
    rate_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY reference (reference),
    UNIQUE KEY user_request (user_id, request_key),
    KEY user_history (user_id, created_at),
    KEY created (created_at),
    CONSTRAINT fx_trades_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defaults (change in Admin › USD ⇄ PHP exchange). Fees in basis points: 50 = 0.50%.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('fx.enabled', '1'),
    ('fx.provider', 'auto'),
    ('fx.buy_fee_bp', '50'),
    ('fx.sell_fee_bp', '50'),
    ('fx.min_usd_cents', '100'),
    ('fx.max_usd_cents', '100000'),
    ('fx.refresh_minutes', '60'),
    ('fx.max_age_hours', '24');
