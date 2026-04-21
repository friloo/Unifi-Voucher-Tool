<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';

session_start();

$db = Database::getInstance();
$auth = new Auth();

// M365 Einstellungen abrufen
$clientId = $db->getSetting('m365_client_id', '');
$clientSecret = $db->getSetting('m365_client_secret', '');
$tenantId = $db->getSetting('m365_tenant_id', '');

if (empty($clientId) || empty($clientSecret) || empty($tenantId)) {
    die('Microsoft 365 ist nicht konfiguriert. Bitte kontaktieren Sie Ihren Administrator. <a href="login.php">Zurück zum Login</a>');
}

// Dynamische Redirect URI
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$scriptPath = $scriptPath === '/' ? '' : $scriptPath;
$redirectUri = $protocol . '://' . $host . $scriptPath . '/m365_callback.php';

// Fehlerbehandlung
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
    $errorDesc = htmlspecialchars($_GET['error_description'] ?? 'Unbekannter Fehler');
    die("Microsoft 365 Login-Fehler: $error<br>$errorDesc<br><a href='login.php'>Zurück zum Login</a>");
}

// Authorization Code erhalten
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Token anfordern
    $tokenUrl = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";
    
    $postData = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
        'scope' => 'openid profile email User.Read'
    ];
    
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        die("Fehler beim Token-Abruf (HTTP $httpCode): " . htmlspecialchars($response) . "<br><a href='login.php'>Zurück zum Login</a>");
    }
    
    $tokenData = json_decode($response, true);
    
    if (!isset($tokenData['access_token'])) {
        die("Kein Access Token erhalten: " . htmlspecialchars($response) . "<br><a href='login.php'>Zurück zum Login</a>");
    }
    
    $accessToken = $tokenData['access_token'];
    
    // Benutzer-Informationen abrufen
    $userUrl = 'https://graph.microsoft.com/v1.0/me';
    
    $ch = curl_init($userUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    
    $userResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        die("Fehler beim Abrufen der Benutzer-Daten (HTTP $httpCode): " . htmlspecialchars($userResponse) . "<br><a href='login.php'>Zurück zum Login</a>");
    }
    
    $userData = json_decode($userResponse, true);
    
    if (!isset($userData['id']) || !isset($userData['mail'])) {
        die("Ungültige Benutzer-Daten erhalten: " . htmlspecialchars($userResponse) . "<br><a href='login.php'>Zurück zum Login</a>");
    }
    
    // Benutzer einloggen oder anlegen
    $microsoftUser = [
        'id' => $userData['id'],
        'email' => $userData['mail'] ?? $userData['userPrincipalName'],
        'name' => $userData['displayName'] ?? $userData['givenName'] . ' ' . $userData['surname']
    ];
    
    try {
        $auth->loginWithMicrosoft($microsoftUser);
        header('Location: index.php');
        exit;
    } catch (Exception $e) {
        die("Login-Fehler: " . $e->getMessage() . "<br><a href='login.php'>Zurück zum Login</a>");
    }
    
} else {
    // Keine Authorization Code - Redirect zu Microsoft Login
    die("Kein Authorization Code erhalten.<br><a href='login.php'>Zurück zum Login</a>");
}
?>