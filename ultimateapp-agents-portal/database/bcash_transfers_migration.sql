-- BCash Send / Receive: person-to-person BCash transfers by BCash QR.
-- Import once after boracay_cash_migration.sql. Safe to run again.
CREATE TABLE IF NOT EXISTS bcash_transfers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL,
    request_key CHAR(64) NOT NULL,
    payer_id INT UNSIGNED NOT NULL,
    payee_id INT UNSIGNED NOT NULL,
    amount_centavos BIGINT UNSIGNED NOT NULL,
    note VARCHAR(140) DEFAULT NULL,
    status ENUM('completed') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY reference (reference),
    UNIQUE KEY payer_request (payer_id, request_key),
    KEY payer_history (payer_id, created_at),
    KEY payee_history (payee_id, created_at),
    CONSTRAINT bcash_transfers_payer_fk FOREIGN KEY (payer_id) REFERENCES users (id),
    CONSTRAINT bcash_transfers_payee_fk FOREIGN KEY (payee_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
