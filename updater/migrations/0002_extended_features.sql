-- Updater-Migration: Schema fuer erweiterte Funktionen
--   * 2FA (TOTP) fuer lokale Accounts
--   * Bandbreiten-/Datenlimits in Voucher-Profilen
--   * REST-API-Schluessel
-- Idempotent gehalten; "duplicate column"/"already exists" werden vom
-- MigrationRunner ignoriert.

ALTER TABLE `users` ADD COLUMN `totp_secret` VARCHAR(64) NULL;
ALTER TABLE `users` ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `voucher_templates` ADD COLUMN `qos_rate_max_down` INT NULL;
ALTER TABLE `voucher_templates` ADD COLUMN `qos_rate_max_up` INT NULL;
ALTER TABLE `voucher_templates` ADD COLUMN `qos_usage_quota` INT NULL;

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `key_prefix` VARCHAR(16) NOT NULL,
  `key_hash` VARCHAR(255) NOT NULL,
  `created_by` INT,
  `last_used_at` TIMESTAMP NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_prefix` (`key_prefix`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
