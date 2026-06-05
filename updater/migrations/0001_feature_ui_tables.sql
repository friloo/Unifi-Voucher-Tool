-- Updater-Migration: Tabellen der UI/Feature-Erweiterung fuer bestehende
-- Installationen nachziehen (Voucher-Templates + Password-Reset-Tokens).
-- Idempotent (CREATE TABLE IF NOT EXISTS) – auf frischen Installationen, die
-- database.sql bereits enthalten, ein No-Op.

CREATE TABLE IF NOT EXISTS `voucher_templates` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `max_uses` INT NOT NULL DEFAULT 1,
  `expire_minutes` INT NOT NULL DEFAULT 480,
  `description` VARCHAR(500),
  `is_active` TINYINT(1) DEFAULT 1,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `token` VARCHAR(128) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_token` (`token`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
