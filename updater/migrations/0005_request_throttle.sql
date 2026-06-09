-- IP-basiertes Request-Throttling (z.B. anonyme Voucher-Erstellung,
-- Passwort-Reset-Anfragen). Ersetzt das rein session-basierte Throttling,
-- das sich per Cookie-Loeschen umgehen liess.
CREATE TABLE IF NOT EXISTS `request_throttle` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `weight` INT NOT NULL DEFAULT 1,
  `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_throttle` (`action`, `ip_address`, `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
