-- UniFi Voucher Management System - Datenbankstruktur

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `setting_key` VARCHAR(100) UNIQUE NOT NULL,
  `setting_value` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sites` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `site_id` VARCHAR(100) NOT NULL,
  `unifi_controller_url` VARCHAR(255) NOT NULL,
  `unifi_username` VARCHAR(100) NOT NULL,
  `unifi_password` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `public_access` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `name` VARCHAR(255),
  `password_hash` VARCHAR(255),
  `is_admin` TINYINT(1) DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `microsoft_id` VARCHAR(255) UNIQUE,
  `last_login` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_microsoft` (`microsoft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_site_access` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `site_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_user_site` (`user_id`, `site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vouchers` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `site_id` INT NOT NULL,
  `user_id` INT,
  `voucher_code` VARCHAR(50) NOT NULL,
  `voucher_name` VARCHAR(255) NOT NULL,
  `max_uses` INT NOT NULL,
  `expire_minutes` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `unifi_voucher_id` VARCHAR(100),
  `status` ENUM('valid', 'used', 'expired') DEFAULT 'valid',
  `used_count` INT DEFAULT 0,
  `expires_at` TIMESTAMP NULL,
  `synced_from_unifi` TINYINT(1) DEFAULT 0,
  `last_sync` TIMESTAMP NULL,
  FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_site` (`site_id`),
  INDEX `idx_created` (`created_at`),
  INDEX `idx_unifi_id` (`unifi_voucher_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration für bestehende Tabellen (falls bereits vorhanden):
-- ALTER TABLE vouchers ADD COLUMN `status` ENUM('valid', 'used', 'expired') DEFAULT 'valid';
-- ALTER TABLE vouchers ADD COLUMN `used_count` INT DEFAULT 0;
-- ALTER TABLE vouchers ADD COLUMN `expires_at` TIMESTAMP NULL;
-- ALTER TABLE vouchers ADD COLUMN `synced_from_unifi` TINYINT(1) DEFAULT 0;
-- ALTER TABLE vouchers ADD COLUMN `last_sync` TIMESTAMP NULL;
-- ALTER TABLE vouchers ADD INDEX `idx_unifi_id` (`unifi_voucher_id`);
-- ALTER TABLE vouchers ADD INDEX `idx_status` (`status`);

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` VARCHAR(128) PRIMARY KEY,
  `user_id` INT NOT NULL,
  `data` TEXT,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;