<?php
/**
 * Cron-Script für Aufräumarbeiten & Datenhaltung (DSGVO).
 *
 * Aufruf via URL: https://domain.de/cron_cleanup.php?token=DEIN_TOKEN
 * Aufruf via CLI: php cron_cleanup.php DEIN_TOKEN
 *
 * Empfohlenes Intervall: täglich.
 *
 * Gesteuert über Einstellungen (0 = deaktiviert):
 *   cleanup_expired_days      – abgelaufene Voucher nach N Tagen aus DB löschen
 *   cleanup_audit_days        – Audit-Log-Einträge nach N Tagen löschen
 *   cleanup_login_days        – Login-Versuche nach N Tagen löschen (Default 30)
 * Abgelaufene/benutzte Passwort-Reset-Tokens werden immer entfernt.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';

function out($data, $isCli) {
    if ($isCli) {
        echo ($data['success'] ? 'SUCCESS' : 'ERROR') . ': ' . ($data['message'] ?? '') . PHP_EOL;
        if (!empty($data['deleted'])) {
            foreach ($data['deleted'] as $k => $v) echo "  - $k: $v" . PHP_EOL;
        }
    } else {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    out(['success' => false, 'message' => 'DB-Fehler: ' . $e->getMessage()], $isCli);
    exit;
}

// Token prüfen (gleicher Token wie cron_sync)
$cronToken = $db->getSetting('cron_token', '');
$provided  = $isCli ? ($argv[1] ?? '') : ($_GET['token'] ?? '');
if (empty($cronToken)) {
    out(['success' => false, 'message' => 'Kein Cron-Token konfiguriert.'], $isCli);
    exit;
}
if (!hash_equals($cronToken, (string)$provided)) {
    out(['success' => false, 'message' => 'Ungültiger Token'], $isCli);
    exit;
}

$expiredDays = (int)$db->getSetting('cleanup_expired_days', 0);
$auditDays   = (int)$db->getSetting('cleanup_audit_days', 0);
$loginDays   = (int)$db->getSetting('cleanup_login_days', 30);

$deleted = [];

try {
    // Abgelaufene Voucher aus der DB entfernen (nur lokale Historie)
    if ($expiredDays > 0) {
        $stmt = $db->query(
            "DELETE FROM vouchers WHERE status = 'expired'
             AND expires_at IS NOT NULL AND expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$expiredDays]
        );
        $deleted['vouchers_expired'] = $stmt->rowCount();
    }

    // Audit-Log nach Aufbewahrungsfrist löschen
    if ($auditDays > 0) {
        try {
            $stmt = $db->query(
                "DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$auditDays]
            );
            $deleted['audit_log'] = $stmt->rowCount();
        } catch (Exception $e) { /* Tabelle evtl. nicht vorhanden */ }
    }

    // Alte Login-Versuche entfernen
    if ($loginDays > 0) {
        try {
            $stmt = $db->query(
                "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$loginDays]
            );
            $deleted['login_attempts'] = $stmt->rowCount();
        } catch (Exception $e) { /* ignore */ }
    }

    // Abgelaufene / benutzte Passwort-Reset-Tokens immer entfernen
    try {
        $stmt = $db->query(
            "DELETE FROM password_reset_tokens WHERE used = 1 OR expires_at < NOW()"
        );
        $deleted['reset_tokens'] = $stmt->rowCount();
    } catch (Exception $e) { /* Tabelle evtl. nicht vorhanden */ }

    $db->query(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('last_cleanup', NOW())
         ON DUPLICATE KEY UPDATE setting_value = NOW()"
    );

    out([
        'success'   => true,
        'message'   => 'Cleanup abgeschlossen',
        'deleted'   => $deleted,
        'timestamp' => date('Y-m-d H:i:s'),
    ], $isCli);
} catch (Exception $e) {
    out(['success' => false, 'message' => 'Fehler: ' . $e->getMessage(), 'deleted' => $deleted], $isCli);
}
