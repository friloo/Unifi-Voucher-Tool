<?php
// Umfassendes Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Versuche Dateien zu laden
$loadErrors = [];

try {
    if (!file_exists(__DIR__ . '/config.php')) {
        throw new Exception('config.php nicht gefunden');
    }
    require_once __DIR__ . '/config.php';
} catch (Exception $e) {
    $loadErrors[] = "Config: " . $e->getMessage();
}

try {
    if (!file_exists(__DIR__ . '/includes/Database.php')) {
        throw new Exception('includes/Database.php nicht gefunden');
    }
    require_once __DIR__ . '/includes/Database.php';
} catch (Exception $e) {
    $loadErrors[] = "Database: " . $e->getMessage();
}

try {
    if (!file_exists(__DIR__ . '/includes/Auth.php')) {
        throw new Exception('includes/Auth.php nicht gefunden');
    }
    require_once __DIR__ . '/includes/Auth.php';
} catch (Exception $e) {
    $loadErrors[] = "Auth: " . $e->getMessage();
}

// Wenn Ladefehler aufgetreten sind, zeige sie an
if (!empty($loadErrors)) {
    die('<h1>Fehler beim Laden der Dateien</h1><ul><li>' . implode('</li><li>', $loadErrors) . '</li></ul>');
}

// Ab hier normal weiter
try {
    $auth = new Auth();
} catch (Exception $e) {
    die('<h1>Fehler bei Auth-Initialisierung</h1><p>' . $e->getMessage() . '</p>');
}

// Wenn bereits eingeloggt, weiterleiten
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Login-Verarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = 'Bitte E-Mail und Passwort eingeben';
        } elseif ($auth->login($email, $password)) {
            header('Location: index.php');
            exit;
        } else {
            $error = 'Ungültige E-Mail oder Passwort';
        }
    } catch (Exception $e) {
        $error = 'Login-Fehler: ' . $e->getMessage();
    }
}

try {
    $db = Database::getInstance();
    $appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
    $logoUrl = $db->getSetting('logo_url', '');
    $m365Enabled = !empty($db->getSetting('m365_client_id')) && 
                   !empty($db->getSetting('m365_client_secret')) && 
                   !empty($db->getSetting('m365_tenant_id'));
    $publicAccess = $db->getSetting('public_access', 0);
    
    // M365 OAuth URL generieren falls aktiviert
    $m365LoginUrl = '';
    if ($m365Enabled) {
        $clientId = $db->getSetting('m365_client_id');
        $tenantId = $db->getSetting('m365_tenant_id');
        
        // Dynamische Redirect URI basierend auf aktuellem Pfad
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        $scriptPath = $scriptPath === '/' ? '' : $scriptPath;
        $redirectUri = $protocol . '://' . $host . $scriptPath . '/m365_callback.php';
        
        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => bin2hex(random_bytes(16))
        ];
        
        $_SESSION['m365_state'] = $params['state'];
        
        $m365LoginUrl = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/authorize?" . http_build_query($params);
    }
} catch (Exception $e) {
    die('<h1>Datenbankfehler</h1><p>' . $e->getMessage() . '</p>');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($appTitle) ?></title>
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
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 420px;
            width: 100%;
            padding: 50px 40px;
            text-align: center;
        }
        .logo {
            max-width: 200px;
            height: auto;
            margin-bottom: 30px;
        }
        h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-microsoft {
            background: white;
            color: #333;
            border: 2px solid #e0e0e0;
            margin-top: 15px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-microsoft:hover {
            background: #f8f9fa;
            border-color: #667eea;
            transform: translateY(-2px);
            text-decoration: none;
        }
        .divider {
            margin: 25px 0;
            text-align: center;
            position: relative;
        }
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e0e0e0;
        }
        .divider span {
            background: white;
            padding: 0 15px;
            color: #999;
            font-size: 13px;
            position: relative;
            z-index: 1;
        }
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
        }
        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #3c3;
        }
        .back-link {
            display: block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            padding: 15px;
            margin-top: 20px;
            border-radius: 8px;
            text-align: left;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <?php if ($logoUrl): ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="logo">
        <?php else: ?>
            <h1><?= htmlspecialchars($appTitle) ?></h1>
        <?php endif; ?>
        
        <p class="subtitle">Melden Sie sich an, um fortzufahren</p>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="email">E-Mail</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn">Anmelden</button>
        </form>
        
        <?php if ($m365Enabled): ?>
            <div class="divider"><span>oder</span></div>
            <a href="<?= htmlspecialchars($m365LoginUrl) ?>" class="btn btn-microsoft">
                🔷 Mit Microsoft 365 anmelden
            </a>
        <?php endif; ?>
        
        <?php if ($publicAccess): ?>
            <a href="index.php" class="back-link">← Zurück zur Code-Erstellung</a>
        <?php endif; ?>
        
        <!-- Debug Info (kann nach erfolgreicher Einrichtung entfernt werden) -->
        <div class="debug-info">
            <strong>System-Status:</strong><br>
            PHP Version: <?= phpversion() ?><br>
            Session Status: <?= session_status() === PHP_SESSION_ACTIVE ? 'Aktiv' : 'Inaktiv' ?><br>
            Eingeloggt: <?= $auth->isLoggedIn() ? 'Ja' : 'Nein' ?>
        </div>
    </div>
</body>
</html>