<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>System Test</h1>";

// 1. PHP Version
echo "<h2>1. PHP Version</h2>";
echo "PHP Version: " . phpversion() . "<br>";

// 2. Config.php vorhanden?
echo "<h2>2. Config.php Check</h2>";
if (file_exists('config.php')) {
    echo "✓ config.php existiert<br>";
    require_once 'config.php';
    echo "✓ config.php geladen<br>";
    echo "DB_HOST: " . DB_HOST . "<br>";
    echo "DB_NAME: " . DB_NAME . "<br>";
} else {
    echo "✗ config.php nicht gefunden!<br>";
}

// 3. Datenbankverbindung
echo "<h2>3. Datenbankverbindung</h2>";
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    echo "✓ Datenbankverbindung erfolgreich<br>";
    
    // Tabellen prüfen
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Gefundene Tabellen: " . implode(", ", $tables) . "<br>";
    
} catch (PDOException $e) {
    echo "✗ Datenbankfehler: " . $e->getMessage() . "<br>";
}

// 4. Includes prüfen
echo "<h2>4. Include-Dateien</h2>";
$files = ['includes/Database.php', 'includes/Auth.php', 'includes/UniFiController.php'];
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "✓ $file existiert<br>";
        try {
            require_once $file;
            echo "✓ $file geladen<br>";
        } catch (Exception $e) {
            echo "✗ Fehler beim Laden von $file: " . $e->getMessage() . "<br>";
        }
    } else {
        echo "✗ $file nicht gefunden!<br>";
    }
}

// 5. Database-Klasse testen
echo "<h2>5. Database-Klasse</h2>";
try {
    $db = Database::getInstance();
    echo "✓ Database::getInstance() erfolgreich<br>";
    
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM users");
    echo "✓ Anzahl Benutzer: " . $result['count'] . "<br>";
    
} catch (Exception $e) {
    echo "✗ Database-Fehler: " . $e->getMessage() . "<br>";
}

// 6. Auth-Klasse testen
echo "<h2>6. Auth-Klasse</h2>";
try {
    $auth = new Auth();
    echo "✓ Auth-Klasse initialisiert<br>";
    echo "Eingeloggt: " . ($auth->isLoggedIn() ? 'Ja' : 'Nein') . "<br>";
    
} catch (Exception $e) {
    echo "✗ Auth-Fehler: " . $e->getMessage() . "<br>";
}

// 7. Session-Test
echo "<h2>7. Session</h2>";
echo "Session Status: " . session_status() . " (1=disabled, 2=active)<br>";
echo "Session ID: " . session_id() . "<br>";

// 8. UniFi API Diagnose
echo "<h2>8. UniFi API Diagnose</h2>";
try {
    $db2 = Database::getInstance();
    $site = $db2->fetchOne("SELECT * FROM sites WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    if (!$site) {
        echo "✗ Keine aktive Site in der Datenbank gefunden<br>";
    } else {
        echo "Site: <strong>" . htmlspecialchars($site['name']) . "</strong><br>";
        echo "Controller URL: <code>" . htmlspecialchars($site['unifi_controller_url']) . "</code><br>";
        echo "Site ID: <code>" . htmlspecialchars($site['site_id']) . "</code><br>";
        echo "Username: <code>" . htmlspecialchars($site['unifi_username']) . "</code><br>";
        echo "<br>";

        // --- Raw Login Test ---
        echo "<strong>Login-Test (raw cURL):</strong><br>";
        $cookieFile = tempnam(sys_get_temp_dir(), 'UNIFI_TEST_');
        $csrfToken  = null;
        $controllerUrl = rtrim($site['unifi_controller_url'], '/');

        $ch = curl_init();
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $controllerUrl . "/api/auth/login",
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'username' => $site['unifi_username'],
                'password' => $site['unifi_password']
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Origin: '  . $controllerUrl,
                'Referer: ' . $controllerUrl . '/login',
            ],
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$csrfToken, &$responseHeaders) {
                $responseHeaders[] = rtrim($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === 'x-csrf-token') {
                    $csrfToken = trim($parts[1]);
                }
                return strlen($header);
            }
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $info     = curl_getinfo($ch);
        curl_close($ch);

        if ($curlErr) {
            echo "✗ cURL Fehler: " . htmlspecialchars($curlErr) . "<br>";
        } else {
            $icon = ($httpCode === 200) ? '✓' : '✗';
            echo "$icon HTTP Code: <strong>$httpCode</strong><br>";
            echo "Effective URL: <code>" . htmlspecialchars($info['url']) . "</code><br>";
        }

        // Response headers
        echo "<br><strong>Response-Header:</strong><pre style='background:#f4f4f4;padding:8px;font-size:12px'>";
        foreach ($responseHeaders as $h) {
            if ($h !== '') echo htmlspecialchars($h) . "\n";
        }
        echo "</pre>";

        // CSRF token
        if ($csrfToken !== null) {
            echo "✓ X-CSRF-Token aus Header: <code>" . htmlspecialchars($csrfToken) . "</code><br>";
        } else {
            echo "✗ Kein X-CSRF-Token im Login-Response-Header gefunden<br>";
            // Check cookie file fallback
            if (file_exists($cookieFile)) {
                $tokenFromCookie = null;
                foreach (file($cookieFile) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') continue;
                    $parts = explode("\t", $line);
                    if (count($parts) >= 7 && strtoupper($parts[5]) === 'TOKEN') {
                        $tokenFromCookie = $parts[6];
                    }
                }
                if ($tokenFromCookie) {
                    echo "✓ CSRF-Token aus Cookie-Datei (Fallback): <code>" . htmlspecialchars($tokenFromCookie) . "</code><br>";
                    $csrfToken = $tokenFromCookie;
                } else {
                    echo "✗ Auch kein TOKEN-Cookie in der Cookie-Datei gefunden<br>";
                }
            }
        }

        // Response body
        echo "<br><strong>Login Response Body:</strong><pre style='background:#f4f4f4;padding:8px;font-size:12px;max-height:200px;overflow:auto'>";
        echo htmlspecialchars(substr($body, 0, 2000));
        echo "</pre>";

        // Cookie file contents
        if (file_exists($cookieFile)) {
            $cookieContents = file_get_contents($cookieFile);
            echo "<strong>Cookie-Datei:</strong><pre style='background:#f4f4f4;padding:8px;font-size:12px'>";
            echo htmlspecialchars($cookieContents ?: '(leer — TOKEN hat Partitioned-Attribut, libcurl schreibt es nicht in die Jar-Datei)');
            echo "</pre>";
        }

        // Extract TOKEN from Set-Cookie header (the fix for Partitioned cookie issue)
        $tokenCookie = null;
        foreach ($responseHeaders as $h) {
            if (stripos($h, 'set-cookie:') === 0) {
                $cookieVal = trim(substr($h, strlen('set-cookie:')));
                $cookieParts = explode(';', $cookieVal);
                $first = trim($cookieParts[0]);
                if (strpos($first, 'TOKEN=') === 0) {
                    $tokenCookie = $first;
                }
            }
        }
        if ($tokenCookie) {
            echo "✓ TOKEN aus Set-Cookie-Header extrahiert: <code>" . htmlspecialchars(substr($tokenCookie, 0, 40)) . "…</code><br>";
        } else {
            echo "✗ TOKEN nicht in Set-Cookie-Header gefunden<br>";
        }

        // --- API Test (only if login succeeded) ---
        if ($httpCode === 200) {
            echo "<br><strong>API-Test (stat/voucher GET) — mit extrahiertem Cookie:</strong><br>";
            $apiHeaders = ['Content-Type: application/json'];
            if ($csrfToken !== null) {
                $apiHeaders[] = 'X-CSRF-Token: ' . $csrfToken;
            }

            $ch2 = curl_init();
            $apiOpts = [
                CURLOPT_URL            => $controllerUrl . "/proxy/network/api/s/" . $site['site_id'] . "/stat/voucher",
                CURLOPT_HTTPGET        => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => $apiHeaders,
            ];
            if ($tokenCookie !== null) {
                $apiOpts[CURLOPT_COOKIE] = $tokenCookie;
            } else {
                $apiOpts[CURLOPT_COOKIEFILE] = $cookieFile;
            }
            curl_setopt_array($ch2, $apiOpts);
            $apiBody = curl_exec($ch2);
            $apiCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $apiErr  = curl_error($ch2);
            curl_close($ch2);

            if ($apiErr) {
                echo "✗ cURL Fehler: " . htmlspecialchars($apiErr) . "<br>";
            } else {
                $icon2 = ($apiCode === 200) ? '✓' : '✗';
                echo "$icon2 HTTP Code: <strong>$apiCode</strong><br>";
            }
            echo "<strong>API Response Body:</strong><pre style='background:#f4f4f4;padding:8px;font-size:12px;max-height:200px;overflow:auto'>";
            echo htmlspecialchars(substr($apiBody, 0, 2000));
            echo "</pre>";
        }

        @unlink($cookieFile);
    }
} catch (Exception $e) {
    echo "✗ Diagnose-Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
}

echo "<hr>";
echo "<h2>✓ Test abgeschlossen</h2>";
echo "<p><a href='login.php'>Zum Login</a> | <a href='index.php'>Zur Startseite</a></p>";
?>