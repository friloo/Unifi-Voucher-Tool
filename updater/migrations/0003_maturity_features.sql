-- Updater-Migration: Reife-Funktionen
--   * 2FA Recovery-/Backup-Codes
--   * API-Scopes & Rate-Limit pro Schlüssel
-- Idempotent; "duplicate column"/"already exists" werden ignoriert.

ALTER TABLE `users` ADD COLUMN `totp_backup_codes` TEXT NULL;

ALTER TABLE `api_keys` ADD COLUMN `scope` VARCHAR(16) NOT NULL DEFAULT 'write';
ALTER TABLE `api_keys` ADD COLUMN `rate_limit` INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `api_key_hits` (
  `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
  `api_key_id` INT NOT NULL,
  `hit_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_key_time` (`api_key_id`, `hit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
