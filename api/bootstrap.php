<?php
/**
 * Gemeinsamer Bootstrap für die REST-API-Endpunkte.
 * Lädt die Basis, authentifiziert den API-Schlüssel und stellt JSON-Helfer bereit.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/UniFiController.php';
require_once __DIR__ . '/../includes/ApiKey.php';

header('Content-Type: application/json; charset=utf-8');

function api_json($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    api_json(['error' => 'database_unavailable'], 503);
}

$apiKeyRow = ApiKey::verify(ApiKey::fromRequest(), $db);
if (!$apiKeyRow) {
    api_json(['error' => 'unauthorized', 'message' => 'Gültiger API-Schlüssel erforderlich (Authorization: Bearer …)'], 401);
}

// Rate-Limit pro Schlüssel
if (!ApiKey::checkRateLimit($apiKeyRow, $db)) {
    header('Retry-After: 60');
    api_json(['error' => 'rate_limited', 'message' => 'Rate-Limit überschritten. Bitte später erneut versuchen.'], 429);
}

/** Erzwingt einen Scope für den aktuellen Schlüssel. */
function api_require_scope($needed) {
    global $apiKeyRow;
    if (!ApiKey::hasScope($apiKeyRow, $needed)) {
        api_json(['error' => 'forbidden', 'message' => "Schlüssel hat keinen '$needed'-Scope"], 403);
    }
}
