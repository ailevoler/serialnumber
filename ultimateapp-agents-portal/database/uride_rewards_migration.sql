-- URide driver trip rewards: a bonus in the driver wallet at trip milestones (default 50, 100, 200, 500, 1000 trips).
-- Import once. Safe to run again (MariaDB).
ALTER TABLE uride_wallet_tx MODIFY type ENUM('topup','ride_fare','commission','payout','payout_returned','adjustment','late_fee','damage_claim','cancel_fee','trip_reward') NOT NULL;

CREATE TABLE IF NOT EXISTS uride_driver_rewards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    milestone INT UNSIGNED NOT NULL,
    amount_centavos INT UNSIGNED NOT NULL,
    ride_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY driver_milestone (driver_id, milestone),
    CONSTRAINT uride_driver_rewards_driver_fk FOREIGN KEY (driver_id) REFERENCES uride_drivers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Milestones as trips:pesos. Change in Admin › Settings › URide › Driver commission › Trip rewards.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('uride.rewards_enabled', '1'),
    ('uride.reward_milestones', '50:100, 100:200, 200:500, 500:1500, 1000:3000');
