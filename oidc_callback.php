<?php
/**
 * Generischer OpenID-Connect-Callback (Authorization-Code-Flow).
 * Konfiguration unter Administration → Integration & Wartung.
 * Nutzt denselben Account-Verknüpfungs-Mechanismus wie M365 (externe SSO-ID).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';

$db   = Database::getInstance();
$auth = new Auth();

$clientId     = $db->getSetting('oidc_client_id', '');
$clientSecret = $db->getSetting('oidc_client_secret', '');
$tokenUrl     = $db->getSetting('oidc_token_url', '');
$userinfoUrl  = $db->getSetting('oidc_userinfo_url', '');

if ($db->getSetting('oidc_enabled', '0') !== '1' || $clientId === '' || $tokenUrl === '' || $userinfoUrl === '') {
    die('OIDC ist nicht konfiguriert. <a href="login.php">Zurück zum Login</a>');
}

if (isset($_GET['error'])) {
    die('OIDC-Fehler: ' . htmlspecialchars($_GET['error']) . '<br><a href="login.php">Zurück zum Login</a>');
}
if (!isset($_GET['code'])) {
    die('Kein Authorization Code erhalten.<br><a href="login.php">Zurück zum Login</a>');
}

// State validieren (CSRF)
$sessionState = $_SESSION['oidc_state'] ?? '';
$returnedState = $_GET['state'] ?? '';
unset($_SESSION['oidc_state']);
if ($sessionState === '' || !hash_equals($sessionState, $returnedState)) {
    die('Ungültiger Sicherheits-Token (state).<br><a href="login.php">Zurück zum Login</a>');
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$scriptPath = $scriptPath === '/' ? '' : $scriptPath;
$redirectUri = $protocol . '://' . $_SERVER['HTTP_HOST'] . $scriptPath . '/oidc_callback.php';

// Code -> Token
$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'redirect_uri'  => $redirectUri,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$token = json_decode((string)$resp, true);

if ($httpCode !== 200 || empty($token['access_token'])) {
    die('Token-Abruf fehlgeschlagen (HTTP ' . $httpCode . ').<br><a href="login.php">Zurück zum Login</a>');
}

// Userinfo abrufen
$ch = curl_init($userinfoUrl);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token'], 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$uResp = curl_exec($ch);
$uCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$info = json_decode((string)$uResp, true);

if ($uCode !== 200 || !is_array($info)) {
    die('Benutzerinfo-Abruf fehlgeschlagen (HTTP ' . $uCode . ').<br><a href="login.php">Zurück zum Login</a>');
}

$sub   = $info['sub'] ?? ($info['id'] ?? '');
$email = $info['email'] ?? ($info['preferred_username'] ?? '');
$name  = $info['name'] ?? trim(($info['given_name'] ?? '') . ' ' . ($info['family_name'] ?? ''));
if ($sub === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die('OIDC lieferte keine gültige Identität (sub/email).<br><a href="login.php">Zurück zum Login</a>');
}

try {
    // Wiederverwendung der externen-SSO-Verknüpfung (microsoft_id = externe ID)
    $auth->loginWithMicrosoft(['id' => 'oidc:' . $sub, 'email' => $email, 'name' => $name ?: $email]);
    header('Location: index.php');
    exit;
} catch (Exception $e) {
    die('Login-Fehler: ' . htmlspecialchars($e->getMessage()) . '<br><a href="login.php">Zurück zum Login</a>');
}
