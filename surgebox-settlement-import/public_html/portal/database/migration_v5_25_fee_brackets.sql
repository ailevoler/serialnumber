-- =====================================================================
-- SurgeBox V5.25 - Client fees: MDR %, Fixed and amount Brackets (set by Admin)
-- Run once in phpMyAdmin > SQL on the live database (safe to run again).
-- =====================================================================

-- New fee type "Bracket": each amount bracket has its own Fixed fee + MDR %.
ALTER TABLE `sb_client_gateways`
  MODIFY `fee_type` enum('None','Fixed','Percentage','Fixed + Percentage','Bracket') NOT NULL DEFAULT 'Fixed',
  ADD COLUMN IF NOT EXISTS `fee_brackets` text DEFAULT NULL COMMENT 'V5.25 JSON amount brackets for fee_type Bracket: [{from,to,fixed,percent}], to NULL = and above' AFTER `fee_type`;
