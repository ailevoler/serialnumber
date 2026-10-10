-- More currencies for the exchange (PHP/USD stays the main pair).
-- Import once AFTER database/fx_migration.sql. Safe to run again (MariaDB).
CREATE TABLE IF NOT EXISTS fx_wallets (
    user_id INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    balance_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Whole minor units: cents, yen, fils',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, currency),
    KEY currency (currency),
    CONSTRAINT fx_wallets_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing USD wallets move into fx_wallets (only once: later runs never overwrite).
INSERT IGNORE INTO fx_wallets (user_id, currency, balance_minor) SELECT user_id, 'USD', balance_cents FROM usd_wallets WHERE balance_cents > 0;

-- Small currencies (IDR, VND, KRW) need more decimals in the rate.
ALTER TABLE fx_rates MODIFY rate DECIMAL(24,10) NOT NULL COMMENT 'PHP per 1 unit of the currency (mid-market)';

ALTER TABLE fx_trades ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER side;
ALTER TABLE fx_trades CHANGE COLUMN IF EXISTS usd_cents amount_minor BIGINT UNSIGNED NOT NULL;
ALTER TABLE fx_trades MODIFY side ENUM('buy','sell','buy_usd','sell_usd') NOT NULL;
UPDATE fx_trades SET side = 'buy' WHERE side = 'buy_usd';
UPDATE fx_trades SET side = 'sell' WHERE side = 'sell_usd';
ALTER TABLE fx_trades MODIFY side ENUM('buy','sell') NOT NULL;
ALTER TABLE fx_trades MODIFY rate DECIMAL(24,10) NOT NULL;
ALTER TABLE fx_trades ADD INDEX IF NOT EXISTS currency_created (currency, created_at);

-- Currencies offered (USD is always on) and the fee for currencies other than USD (100 = 1.00%).
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('fx.currencies', 'USD,EUR,JPY,GBP,AUD,CAD,KRW,CNY,SGD,HKD,TWD,AED,SAR,QAR,KWD,CHF,NZD,THB,MYR,IDR,INR'),
    ('fx.other_buy_fee_bp', '100'),
    ('fx.other_sell_fee_bp', '100');
