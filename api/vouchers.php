<?php
/**
 * REST-Endpunkt für Voucher.
 *
 *   GET  /api/vouchers.php?site_id=<id>        – Voucher einer Site auflisten (aus DB)
 *   POST /api/vouchers.php                     – Voucher erstellen
 *        Body (JSON): {
 *          "site_id": 1, "name": "API Gast", "max_uses": 1,
 *          "expire_minutes": 480,
 *          "qos": { "down": 10000, "up": 2000, "quota_mb": 500 }   (optional)
 *        }
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/Notifier.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    api_require_scope('read');
    $siteId = (int)($_GET['site_id'] ?? 0);
    if ($siteId <= 0) {
        api_json(['error' => 'invalid_request', 'message' => 'site_id erforderlich'], 400);
    }
    $rows = $db->fetchAll(
        "SELECT voucher_code, voucher_name, max_uses, expire_minutes, status, used_count, created_at, expires_at
         FROM vouchers WHERE site_id = ? ORDER BY created_at DESC LIMIT 200",
        [$siteId]
    );
    api_json(['vouchers' => $rows]);
}

if ($method === 'POST') {
    api_require_scope('write');
    $body          = api_body();
    $siteId        = (int)($body['site_id'] ?? 0);
    $name          = trim((string)($body['name'] ?? ''));
    $maxUses       = (int)($body['max_uses'] ?? 1);
    $expireMinutes = (int)($body['expire_minutes'] ?? 480);

    if ($siteId <= 0)        api_json(['error' => 'invalid_request', 'message' => 'site_id erforderlich'], 400);
    if ($name === '')        api_json(['error' => 'invalid_request', 'message' => 'name erforderlich'], 400);
    if ($maxUses < 1)        $maxUses = 1;
    if ($expireMinutes < 1)  $expireMinutes = 480;

    $site = $db->fetchOne("SELECT * FROM sites WHERE id = ? AND is_active = 1", [$siteId]);
    if (!$site) {
        api_json(['error' => 'not_found', 'message' => 'Site nicht gefunden'], 404);
    }

    $qosIn = is_array($body['qos'] ?? null) ? $body['qos'] : [];
    $qos = [
        'down'     => max(0, (int)($qosIn['down'] ?? 0)),
        'up'       => max(0, (int)($qosIn['up'] ?? 0)),
        'quota_mb' => max(0, (int)($qosIn['quota_mb'] ?? 0)),
    ];

    try {
        $fullName   = date('Y-m-d') . '_' . $name;
        $controller = new UniFiController(
            $site['unifi_controller_url'],
            $site['unifi_username'],
            Crypto::decrypt($site['unifi_password']),
            $site['site_id']
        );
        $voucher = $controller->createVoucher($fullName, $maxUses, $expireMinutes, $qos);
        if (!is_array($voucher) || empty($voucher['formatted_code'])) {
            api_json(['error' => 'upstream_error', 'message' => 'UniFi lieferte keinen gültigen Voucher'], 502);
        }

        $db->execute(
            "INSERT INTO vouchers (site_id, user_id, voucher_code, voucher_name, max_uses, expire_minutes, unifi_voucher_id)
             VALUES (?, NULL, ?, ?, ?, ?, ?)",
            [$siteId, $voucher['code'], $fullName, $maxUses, $expireMinutes, $voucher['unifi_id'] ?? null]
        );

        Notifier::voucherCreated(1, $site['name'], 'API: ' . $apiKeyRow['name']);

        api_json([
            'success'        => true,
            'code'           => $voucher['code'],
            'formatted_code' => $voucher['formatted_code'],
            'site'           => $site['name'],
            'max_uses'       => $maxUses,
            'expire_minutes' => $expireMinutes,
        ], 201);
    } catch (Exception $e) {
        api_json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
    }
}

api_json(['error' => 'method_not_allowed'], 405);
