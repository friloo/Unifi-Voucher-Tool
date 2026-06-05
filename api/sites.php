<?php
/**
 * GET /api/sites.php  – Liste aktiver Sites (für API-Clients).
 */
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json(['error' => 'method_not_allowed'], 405);
}

$sites = $db->fetchAll("SELECT id, name, site_id FROM sites WHERE is_active = 1 ORDER BY name");
api_json(['sites' => array_map(function ($s) {
    return ['id' => (int)$s['id'], 'name' => $s['name'], 'site_id' => $s['site_id']];
}, $sites)]);
