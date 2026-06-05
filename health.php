<?php
/**
 * Health-/Status-Endpunkt für Monitoring/Uptime-Checks.
 *
 *   GET /health.php            – Basis-Status (DB erreichbar), kein Geheimnis
 *   GET /health.php?deep=1&token=CRON_TOKEN – zusätzlich Controller-Erreichbarkeit
 *
 * Antwortet HTTP 200 (ok) oder 503 (degraded/fail).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';

$result = ['status' => 'ok', 'time' => date('c'), 'checks' => []];
$httpStatus = 200;

// DB
try {
    $db = Database::getInstance();
    $db->fetchOne("SELECT 1 AS ok");
    $result['checks']['database'] = 'ok';
} catch (Throwable $e) {
    $result['checks']['database'] = 'fail';
    $result['status'] = 'fail';
    $httpStatus = 503;
    echo json_encode($result);
    http_response_code($httpStatus);
    exit;
}

$result['checks']['active_sites'] = (int)($db->fetchOne("SELECT COUNT(*) c FROM sites WHERE is_active=1")['c'] ?? 0);

// Tiefer Check (Controller) nur mit gültigem Cron-Token
if (isset($_GET['deep']) && $_GET['deep'] == '1') {
    $token = $db->getSetting('cron_token', '');
    if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['status' => 'forbidden', 'message' => 'deep check erfordert gültigen token']);
        exit;
    }
    require_once __DIR__ . '/includes/UniFiController.php';
    $controllers = [];
    foreach ($db->fetchAll("SELECT * FROM sites WHERE is_active=1") as $site) {
        $r = UniFiController::testConnection(
            $site['unifi_controller_url'], $site['unifi_username'],
            Crypto::decrypt($site['unifi_password']), $site['site_id']
        );
        $ok = ($r === true);
        $controllers[$site['name']] = $ok ? 'ok' : 'unreachable';
        if (!$ok) { $result['status'] = 'degraded'; $httpStatus = 503; }
    }
    $result['checks']['controllers'] = $controllers;
}

http_response_code($httpStatus);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
