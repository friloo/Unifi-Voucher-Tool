<?php
// Minimaler Test für Cron-Sync Debugging
header('Content-Type: application/json');

echo json_encode(['step' => 1, 'message' => 'PHP läuft']);

// Test 1: Config laden
try {
    require_once __DIR__ . '/config.php';
    echo "\n" . json_encode(['step' => 2, 'message' => 'Config geladen']);
} catch (Exception $e) {
    echo "\n" . json_encode(['step' => 2, 'error' => $e->getMessage()]);
    exit;
}

// Test 2: Database laden
try {
    require_once __DIR__ . '/includes/Database.php';
    echo "\n" . json_encode(['step' => 3, 'message' => 'Database.php geladen']);
} catch (Exception $e) {
    echo "\n" . json_encode(['step' => 3, 'error' => $e->getMessage()]);
    exit;
}

// Test 3: DB-Verbindung
try {
    $db = Database::getInstance();
    echo "\n" . json_encode(['step' => 4, 'message' => 'DB-Verbindung OK']);
} catch (Exception $e) {
    echo "\n" . json_encode(['step' => 4, 'error' => $e->getMessage()]);
    exit;
}

// Test 4: UniFiController laden
try {
    require_once __DIR__ . '/includes/UniFiController.php';
    echo "\n" . json_encode(['step' => 5, 'message' => 'UniFiController.php geladen']);
} catch (Exception $e) {
    echo "\n" . json_encode(['step' => 5, 'error' => $e->getMessage()]);
    exit;
}

// Test 5: Spalten prüfen
try {
    $columns = $db->fetchAll("SHOW COLUMNS FROM vouchers");
    $columnNames = array_column($columns, 'Field');
    echo "\n" . json_encode(['step' => 6, 'message' => 'Voucher-Spalten', 'columns' => $columnNames]);

    // Prüfen ob neue Spalten existieren
    $required = ['status', 'used_count', 'expires_at', 'synced_from_unifi', 'last_sync'];
    $missing = array_diff($required, $columnNames);

    if (!empty($missing)) {
        echo "\n" . json_encode(['step' => 7, 'warning' => 'Fehlende Spalten', 'missing' => array_values($missing)]);
    } else {
        echo "\n" . json_encode(['step' => 7, 'message' => 'Alle Spalten vorhanden']);
    }
} catch (Exception $e) {
    echo "\n" . json_encode(['step' => 6, 'error' => $e->getMessage()]);
    exit;
}

// Test 6: Token prüfen
try {
    $token = $db->getSetting('cron_token', '');
    echo "\n" . json_encode(['step' => 8, 'message' => 'Token Status', 'has_token' => !empty($token)]);
} catch (Exception $e) {
    echo "\n" . json_encode(['step' => 8, 'error' => $e->getMessage()]);
    exit;
}

echo "\n" . json_encode(['final' => 'Alle Tests erfolgreich!']);
