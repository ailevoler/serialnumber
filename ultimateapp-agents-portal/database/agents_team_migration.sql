-- Agents Portal: Master Agents and Sub-Agents.
-- Import ONCE, after agents_migration.sql. Back up the database first.

-- A Sub-Agent belongs to one Master Agent (one level only). NULL = Master Agent.
ALTER TABLE `agents`
  ADD COLUMN `parent_agent_id` int(10) UNSIGNED DEFAULT NULL AFTER `code`,
  ADD KEY `idx_agent_parent` (`parent_agent_id`);

-- One activity can now pay two commissions: the agent's own (direct) and the Master Agent's override.
ALTER TABLE `agent_commissions`
  ADD COLUMN `kind` enum('direct','override') NOT NULL DEFAULT 'direct' AFTER `agent_id`,
  ADD COLUMN `from_agent_id` int(10) UNSIGNED DEFAULT NULL AFTER `kind`,
  DROP INDEX `uniq_commission_source`,
  ADD UNIQUE KEY `uniq_commission_source` (`source_type`, `source_id`, `kind`);

-- Default rates (Admin > Agents > Commission rates). 100 bp = 1%.
-- Sub-Agent rate = what a Sub-Agent earns on their own customers.
-- Master override = what the Master Agent earns on top, on their Sub-Agents' customers (% of the activity amount).
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `is_secret`) VALUES
('agents.sub.uride.percent_bp', '200', 0), ('agents.sub.uride.fixed_centavos', '0', 0),
('agents.sub.upass.percent_bp', '500', 0), ('agents.sub.upass.fixed_centavos', '0', 0),
('agents.sub.ugo.percent_bp', '500', 0), ('agents.sub.ugo.fixed_centavos', '0', 0),
('agents.sub.ulocal.percent_bp', '500', 0), ('agents.sub.ulocal.fixed_centavos', '0', 0),
('agents.sub.ueat.percent_bp', '300', 0), ('agents.sub.ueat.fixed_centavos', '0', 0),
('agents.master.uride.percent_bp', '50', 0), ('agents.master.uride.fixed_centavos', '0', 0),
('agents.master.upass.percent_bp', '100', 0), ('agents.master.upass.fixed_centavos', '0', 0),
('agents.master.ugo.percent_bp', '100', 0), ('agents.master.ugo.fixed_centavos', '0', 0),
('agents.master.ulocal.percent_bp', '100', 0), ('agents.master.ulocal.fixed_centavos', '0', 0),
('agents.master.ueat.percent_bp', '50', 0), ('agents.master.ueat.fixed_centavos', '0', 0),
('agents.sub_auto_approve', '0', 0);
