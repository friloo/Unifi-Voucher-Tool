<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';

// Diagnose-Seite nur fuer angemeldete Admins (leakt sonst M365-Konfiguration)
$auth = new Auth();
$auth->requireAdmin();

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
        <p>Tenant ID: <?= !empty($tenantId) ? '<span class="ok">✓ Gesetzt</span>' : '<span class="error">✗ Fehlt</span>' ?></p>
    </div>

    <div class="section">
        <h2>2. Redirect URI</h2>
        <p>Diese URI muss exakt in der Azure-App-Registrierung hinterlegt sein:</p>
        <div class="url"><?= htmlspecialchars($redirectUri) ?></div>
    </div>

    <div class="section">
        <h2>3. Hinweise</h2>
        <ul>
            <li>Alle drei Werte (Client ID, Client Secret, Tenant ID) müssen gesetzt sein.</li>
            <li>Die Redirect URI in Azure AD muss exakt mit der obigen übereinstimmen.</li>
            <li>Benötigte API-Berechtigungen: <code>openid</code>, <code>profile</code>, <code>email</code>, <code>User.Read</code>.</li>
        </ul>
    </div>
</body>
</html>