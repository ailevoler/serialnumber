-- ============================================================
-- TRILINK – Terminal Dispatching (Pila) migration
-- Run once in phpMyAdmin on u718203951_trilinkdb. Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `tl_terminals` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `terminal_code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `address` varchar(255) NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `vehicle_type` enum('tricycle','e_trike','motorcycle') NOT NULL DEFAULT 'tricycle',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_terminal_code` (`terminal_code`),
  KEY `idx_terminal_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tl_dispatchers` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `dispatcher_code` varchar(20) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `terminal_id` int(10) UNSIGNED NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `pin_attempts` tinyint(3) UNSIGNED DEFAULT 0,
  `pin_locked_until` datetime DEFAULT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dispatcher_code` (`dispatcher_code`),
  UNIQUE KEY `uq_dispatcher_phone` (`phone`),
  KEY `idx_dispatcher_terminal` (`terminal_id`),
  CONSTRAINT `tl_dispatchers_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `tl_terminals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The pila. Position = order of joined_at among status='waiting'.
CREATE TABLE IF NOT EXISTS `tl_terminal_queue` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `terminal_id` int(10) UNSIGNED NOT NULL,
  `driver_id` int(10) UNSIGNED NOT NULL,
  `status` enum('waiting','dispatched','left') NOT NULL DEFAULT 'waiting',
  `booking_id` int(10) UNSIGNED DEFAULT NULL,
  `added_by` enum('driver','dispatcher') NOT NULL DEFAULT 'driver',
  `joined_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_queue_terminal` (`terminal_id`,`status`,`joined_at`),
  KEY `idx_queue_driver` (`driver_id`,`status`),
  KEY `idx_queue_booking` (`booking_id`),
  CONSTRAINT `tl_terminal_queue_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `tl_terminals` (`id`),
  CONSTRAINT `tl_terminal_queue_driver_fk` FOREIGN KEY (`driver_id`) REFERENCES `tl_drivers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ride bookings: 'street' = Not in Terminal (nearby drivers accept),
-- 'terminal' = dispatched from the pila by the terminal dispatcher.
ALTER TABLE `tl_bookings`
  ADD COLUMN IF NOT EXISTS `booking_mode` enum('street','terminal') NOT NULL DEFAULT 'street' AFTER `agent_id`,
  ADD COLUMN IF NOT EXISTS `terminal_id` int(10) UNSIGNED DEFAULT NULL AFTER `booking_mode`,
  ADD COLUMN IF NOT EXISTS `dispatcher_id` int(10) UNSIGNED DEFAULT NULL AFTER `terminal_id`,
  ADD COLUMN IF NOT EXISTS `dispatched_at` datetime DEFAULT NULL AFTER `dispatcher_id`;
ALTER TABLE `tl_bookings` ADD INDEX IF NOT EXISTS `idx_booking_terminal` (`terminal_id`,`status`);

-- Driver work mode, toggled in the driver app:
--   street   = Not in Terminal (gets nearby ride/delivery/order requests)
--   terminal = waits in a terminal pila; only the dispatcher assigns rides
ALTER TABLE `tl_drivers`
  ADD COLUMN IF NOT EXISTS `work_mode` enum('street','terminal') NOT NULL DEFAULT 'street' AFTER `is_online`,
  ADD COLUMN IF NOT EXISTS `work_terminal_id` int(10) UNSIGNED DEFAULT NULL AFTER `work_mode`;

INSERT IGNORE INTO `tl_settings` (`key`,`value`,`description`) VALUES
('terminal_join_radius_m', '500', 'Driver must be within this many meters of the terminal to join the pila himself (0 = no check)');
