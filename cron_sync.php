<?php
/**
 * Cron-Script für automatische Voucher-Synchronisation
 *
 * Aufruf via URL: https://domain.de/cron_sync.php?token=DEIN_TOKEN
 * Aufruf via CLI: php cron_sync.php DEIN_TOKEN
 *
 * Empfohlenes Cron-Intervall: Alle 30 Minuten
 */

// Fehlerbehandlung GANZ am Anfang
error_reporting(E_ALL);
ini_set('display_errors', 0);

// CLI oder Web?
$isCli = php_sapi_name() === 'cli';

// JSON-Header setzen bevor irgendwas anderes passiert
if (!$isCli) {
    header('Content-Type: application/json');
}

// ============================================
// FUNKTIONEN ZUERST DEFINIEREN
// ============================================

function logMessage($message, $isCli) {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] $message";
    if ($isCli) {
        echo $logLine . PHP_EOL;
    }
}

function outputResponse($response, $isCli) {
    if ($isCli) {
        if ($response['success']) {
            echo "SUCCESS: " . ($response['message'] ?? 'OK') . PHP_EOL;
            if (isset($response['results'])) {
                foreach ($response['results'] as $result) {
                    echo "  - {$result['site_name']}: ";
                    if ($result['error']) {
                        echo "FEHLER - {$result['error']}" . PHP_EOL;
                    } else {
                        $s = $result['stats'];
                        echo "Gesamt: {$s['total']}, Neu: {$s['new']}, Aktualisiert: {$s['updated']}, Gültig: {$s['valid']}" . PHP_EOL;
                    }
                }
            }
        } else {
            echo "ERROR: " . $response['message'] . PHP_EOL;
        }
    } else {
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

// ============================================
// ERROR HANDLER
// ============================================

register_shutdown_function(function() use ($isCli) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $response = [
            'success' => false,
            'message' => 'Fatal Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ];
        outputResponse($response, $isCli);
    }
});

set_exception_handler(function($e) use ($isCli) {
    $response = [
        'success' => false,
        'message' => 'Exception: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    outputResponse($response, $isCli);
    exit;
});

// ============================================
// INCLUDES LADEN
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/UniFiController.php';

$db = Database::getInstance();

// ============================================
// TOKEN VALIDIERUNG
// ============================================

$cronToken = $db->getSetting('cron_token', '');

// Token aus GET oder CLI-Argument holen
$providedToken = '';
if ($isCli && isset($argv[1])) {
    $providedToken = $argv[1];
} else {
    $providedToken = $_GET['token'] ?? '';
}

// Prüfen ob Token konfiguriert und korrekt ist
if (empty($cronToken)) {
    outputResponse([
        'success' => false,
        'message' => 'Kein Cron-Token konfiguriert. Bitte im Admin-Bereich unter Einstellungen generieren.'
    ], $isCli);
    exit;
}

if ($providedToken !== $cronToken) {
    outputResponse([
        'success' => false,
        'message' => 'Ungültiger Token'
    ], $isCli);
    exit;
}

// ============================================
// SYNCHRONISATION STARTEN
// ============================================

logMessage("Starte Voucher-Synchronisation...", $isCli);

$startTime = microtime(true);
$results = [];
$totalStats = [
    'sites_processed' => 0,
    'sites_failed' => 0,
    'vouchers_total' => 0,
    'vouchers_new' => 0,
    'vouchers_updated' => 0,
    'vouchers_valid' => 0,
    'vouchers_used' => 0,
    'vouchers_expired' => 0
];

try {
    // Alle aktiven Sites abrufen
    $sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1");

    if (empty($sites)) {
        outputResponse([
            'success' => true,
            'message' => 'Keine aktiven Sites gefunden',
            'results' => []
        ], $isCli);
        exit;
    }

    logMessage("Gefunden: " . count($sites) . " aktive Site(s)", $isCli);

    foreach ($sites as $site) {
        logMessage("Synchronisiere Site: {$site['name']} ({$site['site_id']})...", $isCli);

        $siteResult = [
            'site_id' => $site['id'],
            'site_name' => $site['name'],
            'stats' => null,
            'error' => null
        ];

        try {
            $controller = new UniFiController(
                $site['unifi_controller_url'],
                $site['unifi_username'],
                $site['unifi_password'],
                $site['site_id']
            );

            $stats = $controller->syncVouchersToDatabase($db, $site['id']);
            $siteResult['stats'] = $stats;

            // Gesamtstatistiken aktualisieren
            $totalStats['sites_processed']++;
            $totalStats['vouchers_total'] += $stats['total'];
            $totalStats['vouchers_new'] += $stats['new'];
            $totalStats['vouchers_updated'] += $stats['updated'];
            $totalStats['vouchers_valid'] += $stats['valid'];
            $totalStats['vouchers_used'] += $stats['used'];
            $totalStats['vouchers_expired'] += $stats['expired'];

            logMessage("  OK - {$stats['total']} Voucher(s), {$stats['new']} neu, {$stats['updated']} aktualisiert", $isCli);

        } catch (Exception $e) {
            $siteResult['error'] = $e->getMessage();
            $totalStats['sites_failed']++;
            logMessage("  FEHLER - " . $e->getMessage(), $isCli);
        }

        $results[] = $siteResult;
    }

    // Letzte Sync-Zeit speichern
    $db->execute(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('last_cron_sync', NOW())
         ON DUPLICATE KEY UPDATE setting_value = NOW()"
    );

    $duration = round(microtime(true) - $startTime, 2);

    $response = [
        'success' => true,
        'message' => "Synchronisation abgeschlossen in {$duration}s",
        'duration' => $duration,
        'total' => $totalStats,
        'results' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    logMessage("Synchronisation abgeschlossen in {$duration}s", $isCli);
    logMessage("Gesamt: {$totalStats['vouchers_total']} Voucher(s), {$totalStats['vouchers_new']} neu, {$totalStats['sites_failed']} Fehler", $isCli);

} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Kritischer Fehler: ' . $e->getMessage()
    ];
    logMessage("KRITISCHER FEHLER: " . $e->getMessage(), $isCli);
}

outputResponse($response, $isCli);
