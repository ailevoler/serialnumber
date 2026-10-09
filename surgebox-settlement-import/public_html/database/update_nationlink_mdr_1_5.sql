-- =====================================================================
-- SurgeBox - Nationlink MDR = 1.5% (Nationlink ONLY; PayMongo and other providers are not touched)
-- Run in phpMyAdmin > SQL on the live database. Back up the database first.
--
--   1. Backs up the current Nationlink fee settings and Nationlink transactions.
--   2. Sets every client's Nationlink fee to MDR 1.5% (Percentage), deducted from settlement.
--   3. Recalculates existing Nationlink collections: fee = 1.5% of amount, net = amount - fee.
--
-- Wallet balances: only collections of Nationlink gateways with credit_wallet = 0 are recalculated
-- here (their fee never moved a SurgeBox balance). If a client's Nationlink gateway credits the
-- SurgeBox wallet (credit_wallet = 1), use Admin > client > Payment Credentials > Nationlink >
-- "Recalculate Fees" instead, which also corrects the running balances.
-- Step 0 lists any such gateways.
-- =====================================================================

-- 0) Preview (optional): current Nationlink settings and what the fee will become.
SELECT g.id gateway_id, o.organization_name, g.status, g.credit_wallet, g.fee_type, g.fee_fixed, g.fee_percent, g.fee_mode
FROM sb_client_gateways g JOIN sb_organization o ON o.id = g.organization_id
WHERE g.provider_code = 'nationlink';

SELECT t.id, t.transaction_date, t.reference_no, t.amount,
       t.fee AS old_fee, ROUND(t.amount * 0.015, 2) AS new_fee,
       t.net_amount AS old_net, t.amount - ROUND(t.amount * 0.015, 2) AS new_net
FROM sb_transactions t JOIN sb_client_gateways g ON g.id = t.gateway_id
WHERE g.provider_code = 'nationlink' AND g.credit_wallet = 0
  AND t.type = 'Cash In' AND t.status = 'Completed'
ORDER BY t.id;

-- 1) Backups (kept until you drop them; re-running replaces them).
DROP TABLE IF EXISTS `bk_nl_mdr_gateways`;
CREATE TABLE `bk_nl_mdr_gateways` AS SELECT * FROM sb_client_gateways WHERE provider_code = 'nationlink';
DROP TABLE IF EXISTS `bk_nl_mdr_transactions`;
CREATE TABLE `bk_nl_mdr_transactions` AS
  SELECT t.* FROM sb_transactions t JOIN sb_client_gateways g ON g.id = t.gateway_id
  WHERE g.provider_code = 'nationlink';

START TRANSACTION;

-- 2) Nationlink fee setting for every client: MDR 1.5%, deducted, no fixed fee, no min/max.
UPDATE sb_client_gateways
SET fee_type = 'Percentage', fee_percent = 1.500, fee_fixed = 0.00,
    fee_min = NULL, fee_max = NULL, fee_mode = 'deduct'
WHERE provider_code = 'nationlink';

-- 3) Existing Nationlink collections (same math as the app: round(amount x 1.5%, 2), deducted).
UPDATE sb_transactions t
JOIN sb_client_gateways g ON g.id = t.gateway_id
SET t.fee = ROUND(t.amount * 0.015, 2),
    t.net_amount = t.amount - ROUND(t.amount * 0.015, 2),
    t.fee_mode = IF(ROUND(t.amount * 0.015, 2) > 0, 'deduct', NULL),
    t.fee_status = IF(ROUND(t.amount * 0.015, 2) > 0, 'Deducted', NULL)
WHERE g.provider_code = 'nationlink' AND g.credit_wallet = 0
  AND t.type = 'Cash In' AND t.status = 'Completed';

COMMIT;

-- 4) Check the result.
SELECT g.id gateway_id, o.organization_name, g.fee_type, g.fee_percent, g.fee_fixed, g.fee_mode
FROM sb_client_gateways g JOIN sb_organization o ON o.id = g.organization_id
WHERE g.provider_code = 'nationlink';

SELECT t.id, t.reference_no, t.amount, t.fee, t.net_amount, t.fee_status
FROM sb_transactions t JOIN sb_client_gateways g ON g.id = t.gateway_id
WHERE g.provider_code = 'nationlink'
ORDER BY t.id;

-- Undo (only if needed):
--   UPDATE sb_client_gateways g JOIN bk_nl_mdr_gateways b ON b.id = g.id
--     SET g.fee_type = b.fee_type, g.fee_fixed = b.fee_fixed, g.fee_percent = b.fee_percent,
--         g.fee_min = b.fee_min, g.fee_max = b.fee_max, g.fee_mode = b.fee_mode;
--   UPDATE sb_transactions t JOIN bk_nl_mdr_transactions b ON b.id = t.id
--     SET t.fee = b.fee, t.net_amount = b.net_amount, t.fee_mode = b.fee_mode, t.fee_status = b.fee_status;
-- When everything looks right:  DROP TABLE bk_nl_mdr_gateways; DROP TABLE bk_nl_mdr_transactions;
