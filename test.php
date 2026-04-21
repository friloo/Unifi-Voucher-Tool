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

echo "<hr>";
echo "<h2>✓ Test abgeschlossen</h2>";
echo "<p><a href='login.php'>Zum Login</a> | <a href='index.php'>Zur Startseite</a></p>";
?>