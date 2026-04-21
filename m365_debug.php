<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';

$db = Database::getInstance();

// M365 Einstellungen abrufen
$clientId = $db->getSetting('m365_client_id', '');
$clientSecret = $db->getSetting('m365_client_secret', '');
$tenantId = $db->getSetting('m365_tenant_id', '');

// Dynamische URLs
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$scriptPath = $scriptPath === '/' ? '' : $scriptPath;
$redirectUri = $protocol . '://' . $host . $scriptPath . '/m365_callback.php';

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M365 Debug</title>
    <style>
        body {
            font-family: monospace;
            padding: 20px;
            background: #f5f5f5;
        }
        .section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        h2 {
            margin-top: 0;
            color: #333;
        }
        .ok {
            color: green;
            font-weight: bold;
        }
        .error {
            color: red;
            font-weight: bold;
        }
        .info {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }
        code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .copyable {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <h1>🔍 Microsoft 365 OAuth Debug</h1>
    
    <div class="section">
        <h2>1. Konfiguration Status</h2>
        <p>Client ID: <?= !empty($clientId) ? '<span class="ok">✓ Gesetzt</span>' : '<span class="error">✗ Fehlt</span>' ?></p>
        <p>Client Secret: <?= !empty($clientSecret) ? '<span class="ok">✓ Gesetzt</span>' : '<span class="error">✗ Fehlt</span>' ?></p>
        <p>Tenant ID: <?= !empty($tenant