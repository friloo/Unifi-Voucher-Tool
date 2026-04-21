<?php
session_start();

// Prüfen ob bereits installiert
if (file_exists(__DIR__ . '/config.php') && !isset($_GET['reinstall'])) {
    die('System bereits installiert. Wenn Sie neu installieren möchten, löschen Sie die config.php oder rufen Sie install.php?reinstall=1 auf.');
}

$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
$errors = [];
$success = false;

// Step 1: Datenbankverbindung testen
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? '';
    $db_name = $_POST['db_name'] ?? '';
    $db_user = $_POST['db_user'] ?? '';
    $db_pass = $_POST['db_pass'] ?? '';
    
    try {
        $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Datenbank erstellen falls nicht vorhanden
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db_name`");
        
        // Tabellen erstellen
        $sql = file_get_contents(__DIR__ . '/database.sql');
        $pdo->exec($sql);
        
        $_SESSION['install_db'] = [
            'host' => $db_host,
            'name' => $db_name,
            'user' => $db_user,
            'pass' => $db_pass
        ];
        
    } catch (PDOException $e) {
        $errors[] = "Datenbankfehler: " . $e->getMessage();
        $step = 1;
    }
}

// Step 2: Admin-Account erstellen
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_email = filter_var($_POST['admin_email'] ?? '', FILTER_VALIDATE_EMAIL);
    $admin_name = $_POST['admin_name'] ?? '';
    $admin_password = $_POST['admin_password'] ?? '';
    $admin_password_confirm = $_POST['admin_password_confirm'] ?? '';
    
    if (!$admin_email) {
        $errors[] = "Ungültige E-Mail-Adresse";
        $step = 2;
    } elseif (strlen($admin_password) < 8) {
        $errors[] = "Passwort muss mindestens 8 Zeichen lang sein";
        $step = 2;
    } elseif ($admin_password !== $admin_password_confirm) {
        $errors[] = "Passwörter stimmen nicht überein";
        $step = 2;
    } else {
        $_SESSION['install_admin'] = [
            'email' => $admin_email,
            'name' => $admin_name,
            'password' => password_hash($admin_password, PASSWORD_DEFAULT)
        ];
    }
}

// Step 3: Allgemeine Einstellungen
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_title = $_POST['app_title'] ?? 'UniFi Voucher System';
    $logo_url = $_POST['logo_url'] ?? '';
    $instruction_header = $_POST['instruction_header'] ?? '';
    $instruction_text = $_POST['instruction_text'] ?? '';
    $public_access = isset($_POST['public_access']) ? 1 : 0;
    
    // Microsoft 365 OAuth (optional)
    $m365_client_id = $_POST['m365_client_id'] ?? '';
    $m365_client_secret = $_POST['m365_client_secret'] ?? '';
    $m365_tenant_id = $_POST['m365_tenant_id'] ?? '';
    
    $_SESSION['install_settings'] = [
        'app_title' => $app_title,
        'logo_url' => $logo_url,
        'instruction_header' => $instruction_header,
        'instruction_text' => $instruction_text,
        'public_access' => $public_access,
        'm365_client_id' => $m365_client_id,
        'm365_client_secret' => $m365_client_secret,
        'm365_tenant_id' => $m365_tenant_id
    ];
}

// Step 4: Installation abschließen
if ($step === 5 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = $_SESSION['install_db'];
        $admin = $_SESSION['install_admin'];
        $settings = $_SESSION['install_settings'];
        
        $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Admin-User erstellen
        $stmt = $pdo->prepare("INSERT INTO users (email, name, password_hash, is_admin, is_active) VALUES (?, ?, ?, 1, 1)");
        $stmt->execute([$admin['email'], $admin['name'], $admin['password']]);
        
        // Settings speichern
        $settingsData = [
            'app_title' => $settings['app_title'],
            'logo_url' => $settings['logo_url'],
            'instruction_header' => $settings['instruction_header'],
            'instruction_text' => $settings['instruction_text'],
            'public_access' => $settings['public_access'],
            'm365_client_id' => $settings['m365_client_id'],
            'm365_client_secret' => $settings['m365_client_secret'],
            'm365_tenant_id' => $settings['m365_tenant_id']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settingsData as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        
        // config.php erstellen
        $configContent = "<?php\n";
        $configContent .= "// UniFi Voucher Management System - Configuration\n\n";
        $configContent .= "define('DB_HOST', '{$db['host']}');\n";
        $configContent .= "define('DB_NAME', '{$db['name']}');\n";
        $configContent .= "define('DB_USER', '{$db['user']}');\n";
        $configContent .= "define('DB_PASS', '" . addslashes($db['pass']) . "');\n\n";
        $configContent .= "// Sitzungs-Einstellungen\n";
        $configContent .= "define('SESSION_LIFETIME', 3600); // 1 Stunde\n\n";
        $configContent .= "// Zeitzone\n";
        $configContent .= "date_default_timezone_set('Europe/Berlin');\n";
        
        file_put_contents(__DIR__ . '/config.php', $configContent);
        
        // .htaccess erstellen (ohne Rewrite Rules die Probleme machen)
        $htaccess = "# UniFi Voucher System\n\n";
        $htaccess .= "# Security\n";
        $htaccess .= "<FilesMatch \"(config\\.php|database\\.sql|install\\.php|test\\.php|\\.md)$\">\n";
        $htaccess .= "    Order Allow,Deny\n";
        $htaccess .= "    Deny from all\n";
        $htaccess .= "</FilesMatch>\n\n";
        $htaccess .= "DirectoryIndex index.php\n";
        file_put_contents(__DIR__ . '/.htaccess', $htaccess);
        
        $success = true;
        
        // Session-Daten löschen
        unset($_SESSION['install_db'], $_SESSION['install_admin'], $_SESSION['install_settings']);
        
    } catch (Exception $e) {
        $errors[] = "Fehler bei der Installation: " . $e->getMessage();
        $step = 4;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniFi Voucher System - Installation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        h1 { color: #333; margin-bottom: 10px; font-size: 28px; }
        h2 { color: #667eea; margin-bottom: 20px; font-size: 20px; font-weight: 500; }
        .progress {
            display: flex;
            justify-content: space-between;
            margin: 30px 0;
            position: relative;
        }
        .progress::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e0e0e0;
            z-index: 0;
        }
        .progress-step {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #999;
            position: relative;
            z-index: 1;
        }
        .progress-step.active {
            background: #667eea;
            color: white;
        }
        .progress-step.completed {
            background: #4caf50;
            color: white;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"],
        textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
        }
        .checkbox-group input {
            width: auto;
            margin-right: 10px;
        }
        .btn {
            background: #667eea;
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
            width: 100%;
        }
        .btn:hover {
            background: #5568d3;
        }
        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .success {
            background: #efe;
            border: 1px solid #cfc;
            color: #3c3;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .help-text {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
        }
        .section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .section h3 {
            margin-bottom: 15px;
            color: #333;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 UniFi Voucher System</h1>
        <h2>Installation</h2>
        
        <div class="progress">
            <div class="progress-step <?= $step >= 1 ? 'completed' : '' ?>">1</div>
            <div class="progress-step <?= $step >= 2 ? 'completed' : ($step === 1 ? 'active' : '') ?>">2</div>
            <div class="progress-step <?= $step >= 3 ? 'completed' : ($step === 2 ? 'active' : '') ?>">3</div>
            <div class="progress-step <?= $step >= 4 ? 'completed' : ($step === 3 ? 'active' : '') ?>">4</div>
            <div class="progress-step <?= $step >= 5 ? 'active' : '' ?>">5</div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <strong>✓ Installation erfolgreich abgeschlossen!</strong><br>
                Sie können sich jetzt mit Ihren Admin-Zugangsdaten anmelden.
            </div>
            <a href="index.php" class="btn">Zum Login</a>
        <?php elseif ($step === 1): ?>
            <form method="post">
                <input type="hidden" name="step" value="2">
                <h3>Schritt 1: Datenbank-Konfiguration</h3>
                
                <div class="form-group">
                    <label>Datenbank-Host</label>
                    <input type="text" name="db_host" value="localhost" required>
                    <div class="help-text">Meist "localhost"</div>
                </div>
                
                <div class="form-group">
                    <label>Datenbankname</label>
                    <input type="text" name="db_name" value="unifi_voucher" required>
                    <div class="help-text">Name der Datenbank (wird erstellt falls nicht vorhanden)</div>
                </div>
                
                <div class="form-group">
                    <label>Datenbank-Benutzer</label>
                    <input type="text" name="db_user" required>
                </div>
                
                <div class="form-group">
                    <label>Datenbank-Passwort</label>
                    <input type="password" name="db_pass">
                </div>
                
                <button type="submit" class="btn">Weiter →</button>
            </form>
        
        <?php elseif ($step === 2): ?>
            <form method="post">
                <input type="hidden" name="step" value="3">
                <h3>Schritt 2: Administrator-Account</h3>
                
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="admin_name" required>
                </div>
                
                <div class="form-group">
                    <label>E-Mail</label>
                    <input type="email" name="admin_email" required>
                </div>
                
                <div class="form-group">
                    <label>Passwort</label>
                    <input type="password" name="admin_password" required minlength="8">
                    <div class="help-text">Mindestens 8 Zeichen</div>
                </div>
                
                <div class="form-group">
                    <label>Passwort bestätigen</label>
                    <input type="password" name="admin_password_confirm" required>
                </div>
                
                <button type="submit" class="btn">Weiter →</button>
            </form>
        
        <?php elseif ($step === 3): ?>
            <form method="post">
                <input type="hidden" name="step" value="4">
                <h3>Schritt 3: Allgemeine Einstellungen</h3>
                
                <div class="form-group">
                    <label>Anwendungs-Titel</label>
                    <input type="text" name="app_title" value="UniFi Voucher System" required>
                </div>
                
                <div class="form-group">
                    <label>Logo-URL (optional)</label>
                    <input type="text" name="logo_url" placeholder="https://example.com/logo.png">
                </div>
                
                <div class="form-group">
                    <label>Anleitung Überschrift</label>
                    <input type="text" name="instruction_header" value="So verwenden Sie Ihren Code">
                </div>
                
                <div class="form-group">
                    <label>Anleitung Text</label>
                    <textarea name="instruction_text">Verbinden Sie sich mit dem WLAN und geben Sie den Code auf der Anmeldeseite ein.</textarea>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="public_access" id="public_access">
                    <label for="public_access" style="margin: 0;">Öffentlicher Zugriff auf Code-Erstellung</label>
                </div>
                
                <div class="section">
                    <h3>Microsoft 365 Login (Optional)</h3>
                    <div class="form-group">
                        <label>Client ID</label>
                        <input type="text" name="m365_client_id">
                    </div>
                    <div class="form-group">
                        <label>Client Secret</label>
                        <input type="password" name="m365_client_secret">
                    </div>
                    <div class="form-group">
                        <label>Tenant ID</label>
                        <input type="text" name="m365_tenant_id">
                    </div>
                    <div class="help-text">Leer lassen, wenn M365-Login nicht verwendet werden soll</div>
                </div>
                
                <button type="submit" class="btn">Weiter →</button>
            </form>
        
        <?php elseif ($step === 4): ?>
            <form method="post">
                <input type="hidden" name="step" value="5">
                <h3>Schritt 4: Installation abschließen</h3>
                
                <p style="margin-bottom: 20px; color: #666;">
                    Klicken Sie auf "Installation abschließen", um die Einrichtung zu beenden.
                    Die Datenbank und alle notwendigen Dateien werden erstellt.
                </p>
                
                <button type="submit" class="btn">Installation abschließen</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>