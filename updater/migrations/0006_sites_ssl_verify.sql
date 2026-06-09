-- Opt-in SSL-Zertifikatspruefung pro Site (Default aus: UniFi-Controller
-- nutzen meist self-signed Zertifikate).
ALTER TABLE `sites` ADD COLUMN `ssl_verify` TINYINT(1) NOT NULL DEFAULT 0;
