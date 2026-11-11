-- ============================================================
--  SG PANEL (MultiPanelX) -- CLEAN INSTALLATION SQL
-- ------------------------------------------------------------
--  This script:
--    1. Creates all tables (schema preserved)
--    2. Keeps ALL structure, indexes, and foreign keys
--    3. Inserts ONLY the default admin user
--    4. All other data (users, orders, keys, mods, plans,
--       transactions, payments, sessions) is LEFT EMPTY
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- --------------------------------------------------------
-- Table: license_keys
-- --------------------------------------------------------
CREATE TABLE `license_keys` (
  `id` int(11) NOT NULL,
  `mod_id` int(11) NOT NULL,
  `license_key` varchar(500) NOT NULL,
  `provider_key` varchar(500) DEFAULT NULL,
  `duration` int(11) NOT NULL,
  `duration_type` varchar(50) DEFAULT 'days',
  `price` decimal(10,2) NOT NULL,
  `status` enum('available','sold','blocked','expired') DEFAULT 'available',
  `sold_to` int(11) DEFAULT NULL,
  `sold_at` timestamp NULL DEFAULT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: mods
-- --------------------------------------------------------
CREATE TABLE `mods` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `provider_pid` varchar(20) DEFAULT NULL,
  `requires_device_id` tinyint(1) DEFAULT 0,
  `version` varchar(50) DEFAULT NULL,
  `features` text DEFAULT NULL,
  `purchase_link` varchar(255) DEFAULT NULL,
  `btn1_label` varchar(100) DEFAULT NULL,
  `btn1_link` varchar(500) DEFAULT NULL,
  `btn2_label` varchar(100) DEFAULT NULL,
  `btn2_link` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive','under_maintenance') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: mod_apks
-- --------------------------------------------------------
CREATE TABLE `mod_apks` (
  `id` int(11) NOT NULL,
  `mod_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: mod_plans
-- --------------------------------------------------------
CREATE TABLE `mod_plans` (
  `id` int(11) NOT NULL,
  `mod_id` int(11) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `duration` int(11) NOT NULL DEFAULT 30,
  `duration_type` varchar(50) DEFAULT 'days',
  `provider_duration` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reseller_price` decimal(10,2) DEFAULT NULL,
  `features` text DEFAULT NULL,
  `status` enum('active','inactive','stock_out') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: payment_orders
-- --------------------------------------------------------
CREATE TABLE `payment_orders` (
  `id` int(11) NOT NULL,
  `payment_id` varchar(100) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `purpose` enum('plan','wallet') NOT NULL DEFAULT 'plan',
  `plan_id` int(11) DEFAULT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `utr` varchar(100) DEFAULT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `paid_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: referral_codes
-- --------------------------------------------------------
CREATE TABLE `referral_codes` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `created_by` int(11) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: referral_commissions
-- --------------------------------------------------------
CREATE TABLE `referral_commissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `referrer_id` int(11) NOT NULL COMMENT 'User/reseller who owns the referral link',
  `referred_user_id` int(11) NOT NULL COMMENT 'User who was referred and bought a key',
  `order_amount` decimal(10,2) NOT NULL COMMENT 'Key purchase amount (the price paid)',
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 5.00 COMMENT 'Commission percentage applied',
  `commission_amount` decimal(10,2) NOT NULL COMMENT '5% of order amount credited to referrer',
  `source` enum('wallet','reseller_api') NOT NULL DEFAULT 'wallet' COMMENT 'Where the purchase came from',
  `plan_id` int(11) DEFAULT NULL COMMENT 'Plan that was purchased',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_referrer` (`referrer_id`),
  KEY `idx_referred` (`referred_user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: transactions
-- --------------------------------------------------------
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('purchase','balance_add','refund') NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','completed','failed','rejected') DEFAULT 'pending',
  `plan_id` int(11) DEFAULT NULL,
  `upi_txn_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: users
-- --------------------------------------------------------
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  `role` enum('admin','user') DEFAULT 'user',
  `is_reseller` tinyint(1) NOT NULL DEFAULT 0,
  `total_spent` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reseller_api_key` varchar(64) DEFAULT NULL,
  `reseller_api_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `became_reseller_at` datetime DEFAULT NULL,
  `referral_code` varchar(20) DEFAULT NULL,
  `oauth_uid` varchar(255) DEFAULT NULL,
  `oauth_provider` varchar(30) DEFAULT NULL,
  `referred_by` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: user_sessions
-- --------------------------------------------------------
CREATE TABLE `user_sessions` (
  `user_id` int(11) NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Indexes
-- --------------------------------------------------------
ALTER TABLE `license_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `license_key` (`license_key`),
  ADD KEY `mod_id` (`mod_id`),
  ADD KEY `sold_to` (`sold_to`);

ALTER TABLE `mods`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `mod_apks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mod_id` (`mod_id`);

ALTER TABLE `mod_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mod_id` (`mod_id`);

ALTER TABLE `payment_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_id` (`payment_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `plan_id` (`plan_id`);

ALTER TABLE `referral_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `plan_id` (`plan_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `referral_code` (`referral_code`),
  ADD UNIQUE KEY `uniq_oauth` (`oauth_provider`,`oauth_uid`),
  ADD UNIQUE KEY `uniq_reseller_api_key` (`reseller_api_key`);

ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`user_id`);

-- --------------------------------------------------------
-- AUTO_INCREMENT
-- --------------------------------------------------------
ALTER TABLE `license_keys`      MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `mods`              MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `mod_apks`          MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `mod_plans`         MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `payment_orders`    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `referral_codes`    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `transactions`      MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `users`             MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

-- --------------------------------------------------------
-- Foreign Key Constraints
-- --------------------------------------------------------
ALTER TABLE `license_keys`
  ADD CONSTRAINT `license_keys_ibfk_1` FOREIGN KEY (`mod_id`) REFERENCES `mods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `license_keys_ibfk_2` FOREIGN KEY (`sold_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `mod_apks`
  ADD CONSTRAINT `mod_apks_ibfk_1` FOREIGN KEY (`mod_id`) REFERENCES `mods` (`id`) ON DELETE CASCADE;

ALTER TABLE `mod_plans`
  ADD CONSTRAINT `mod_plans_ibfk_1` FOREIGN KEY (`mod_id`) REFERENCES `mods` (`id`) ON DELETE CASCADE;

ALTER TABLE `referral_codes`
  ADD CONSTRAINT `referral_codes_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `mod_plans` (`id`) ON DELETE SET NULL;

ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------
-- DEFAULT ADMIN USER (ONLY ROW INSERTED)
-- Username : admin
-- Email    : admin@example.com
-- Password : admin123
-- --------------------------------------------------------
INSERT INTO `users`
  (`id`, `username`, `email`, `password`, `balance`, `role`, `is_reseller`, `total_spent`, `reseller_api_enabled`, `created_at`)
VALUES
  (1, 'admin', 'admin@example.com',
   '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1HlCS4bZJ18JuywdBq3iVyrKfSPXmEi',
   0.00, 'admin', 0, 0.00, 1, NOW());

COMMIT;