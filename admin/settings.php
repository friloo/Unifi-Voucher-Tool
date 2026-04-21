<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

$error = '';
$success = '';

// Einstellungen speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            $settings = [];
            $formType = $_POST['form_type'] ?? '';
            
            // Allgemeine Einstellungen
            if ($formType === 'general') {
                $settings['app_title'] = trim($_POST['app_title'] ?? '');
                $settings['logo_url'] = trim($_POST['logo_url'] ?? '');
                $settings['favicon_url'] = trim($_POST['favicon_url'] ?? '');
                $settings['instruction_header'] = trim($_POST['instruction_header'] ?? '');
                $settings['instruction_text'] = $_POST['instruction_text'] ?? '';
                $settings['public_access'] = isset($_POST['public_access']) ? '1' : '0';
            }
            
            // M365 Einstellungen
            if ($formType === 'm365') {
                $settings['m365_client_id'] = trim($_POST['m365_client_id'] ?? '');
                $settings['m365_client_secret'] = trim($_POST['m365_client_secret'] ?? '');
                $settings['m365_tenant_id'] = trim($_POST['m365_tenant_id'] ?? '');
            }
            
            // SMTP Einstellungen
            if ($formType === 'smtp') {
                $settings['smtp_enabled'] = isset($_POST['smtp_enabled']) ? '1' : '0';
                $settings['smtp_host'] = trim($_POST['smtp_host'] ?? '');
                $settings['smtp_port'] = trim($_POST['smtp_port'] ?? '587');
                $settings['smtp_username'] = trim($_POST['smtp_username'] ?? '');
                if (!empty($_POST['smtp_password'])) {
                    $settings['smtp_password'] = trim($_POST['smtp_password']);
                }
                $settings['smtp_encryption'] = trim($_POST['smtp_encryption'] ?? 'tls');
                $settings['smtp_from_email'] = trim($_POST['smtp_from_email'] ?? '');
                $settings['smtp_from_name'] = trim($_POST['smtp_from_name'] ?? '');
            }
            
            // E-Mail Templates
            if ($formType === 'templates') {
                $settings['email_voucher_subject'] = trim($_POST['email_voucher_subject'] ?? '');
                $settings['email_voucher_body'] = $_POST['email_voucher_body'] ?? '';
                $settings['email_user_notification_subject'] = trim($_POST['email_user_notification_subject'] ?? '');
                $settings['email_user_notification_body'] = $_POST['email_user_notification_body'] ?? '';
                $settings['system_url'] = trim($_POST['system_url'] ?? '');
            }
            
            // System Einstellungen
            if ($formType === 'system') {
                $settings['tinymce_api_key'] = trim($_POST['tinymce_api_key'] ?? '');
                $settings['print_template'] = $_POST['print_template'] ?? '';
            }
            
            foreach ($settings as $key => $value) {
                $db->setSetting($key, $value);
            }
            
            $success = 'Einstellungen erfolgreich gespeichert!';
            
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// Cron-Token generieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_cron_token'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            // Sicheren Token generieren
            $newToken = bin2hex(random_bytes(32));
            $db->setSetting('cron_token', $newToken);
            $success = 'Neuer Cron-Token wurde generiert!';
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// Cron-Token löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cron_token'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            $db->setSetting('cron_token', '');
            $success = 'Cron-Token wurde gelöscht!';
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// Passwort ändern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            $currentPassword = $_POST['current_password'];
            $newPassword = $_POST['new_password'];
            $confirmPassword = $_POST['confirm_password'];
            
            $user = $auth->getCurrentUser();
            
            if (!password_verify($currentPassword, $user['password_hash'])) {
                throw new Exception('Aktuelles Passwort ist falsch');
            }
            
            if (strlen($newPassword) < 8) {
                throw new Exception('Neues Passwort muss mindestens 8 Zeichen lang sein');
            }
            
            if ($newPassword !== $confirmPassword) {
                throw new Exception('Passwörter stimmen nicht überein');
            }
            
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->query("UPDATE users SET password_hash = ? WHERE id = ?", [$newHash, $user['id']]);
            
            $success = 'Passwort erfolgreich geändert!';
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Aktuelle Einstellungen laden
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['SCRIPT_NAME'], 2);
$scriptPath = $scriptPath === '/' ? '' : $scriptPath;
$autoDetectedUrl = $protocol . '://' . $host . $scriptPath;

$currentSettings = [
    'app_title' => $db->getSetting('app_title', 'UniFi Voucher System'),
    'logo_url' => $db->getSetting('logo_url', ''),
    'favicon_url' => $db->getSetting('favicon_url', ''),
    'instruction_header' => $db->getSetting('instruction_header', 'So verwenden Sie Ihren Code'),
    'instruction_text' => $db->getSetting('instruction_text', 'Verbinden Sie sich mit dem WLAN und geben Sie den Code ein.'),
    'public_access' => $db->getSetting('public_access', '0'),
    'm365_client_id' => $db->getSetting('m365_client_id', ''),
    'm365_client_secret' => $db->getSetting('m365_client_secret', ''),
    'm365_tenant_id' => $db->getSetting('m365_tenant_id', ''),
    'smtp_enabled' => $db->getSetting('smtp_enabled', '0'),
    'smtp_host' => $db->getSetting('smtp_host', ''),
    'smtp_port' => $db->getSetting('smtp_port', '587'),
    'smtp_username' => $db->getSetting('smtp_username', ''),
    'smtp_password' => $db->getSetting('smtp_password', ''),
    'smtp_encryption' => $db->getSetting('smtp_encryption', 'tls'),
    'smtp_from_email' => $db->getSetting('smtp_from_email', ''),
    'smtp_from_name' => $db->getSetting('smtp_from_name', ''),
    'system_url' => $db->getSetting('system_url', $autoDetectedUrl),
    'email_voucher_subject' => $db->getSetting('email_voucher_subject', '{APP_TITLE} - Ihr WLAN-Zugang'),
    'email_voucher_body' => $db->getSetting('email_voucher_body', "Hallo,\n\nIhr Code: {VOUCHER_CODE}\n\nGültigkeit: 8h\nGeräte: {MAX_USES}\nSite: {SITE_NAME}"),
    'email_user_notification_subject' => $db->getSetting('email_user_notification_subject', '{APP_TITLE} - Berechtigungen geändert'),
    'email_user_notification_body' => $db->getSetting('email_user_notification_body', "Hallo {USER_NAME},\n\n{CHANGES}"),
    'tinymce_api_key' => $db->getSetting('tinymce_api_key', ''),
    'print_template' => $db->getSetting('print_template', '<div style="text-align:center;padding:40px"><h1>{APP_TITLE}</h1><h2>WLAN Code</h2><div style="font-size:48px;font-weight:bold;margin:30px 0;font-family:monospace">{VOUCHER_CODE}</div><p><strong>Gültig bis:</strong> {EXPIRY_DATE} {EXPIRY_TIME}</p><p><strong>Site:</strong> {SITE_NAME}</p><p><strong>Geräte:</strong> {MAX_USES}</p><hr style="margin:30px 0"><div>{INSTRUCTIONS}</div></div>'),
    'cron_token' => $db->getSetting('cron_token', ''),
    'last_cron_sync' => $db->getSetting('last_cron_sync', '')
];

$currentUser = $auth->getCurrentUser();
$faviconUrl = $db->getSetting('favicon_url', '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen - <?= htmlspecialchars($appTitle) ?></title>
    <?php if ($faviconUrl): ?>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- TinyMCE -->
    <?php if (!empty($currentSettings['tinymce_api_key'])): ?>
    <script src="https://cdn.tiny.cloud/1/<?= htmlspecialchars($currentSettings['tinymce_api_key']) ?>/tinymce/6/tinymce.min.js"></script>
    <?php else: ?>
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js"></script>
    <?php endif; ?>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; }
        .header { background: white; border-bottom: 1px solid #e0e0e0; padding: 0 30px; height: 70px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .header-title { font-size: 20px; font-weight: 600; color: #333; }
        .sidebar { position: fixed; left: 0; top: 70px; bottom: 0; width: 260px; background: white; border-right: 1px solid #e0e0e0; padding: 30px 0; }
        .sidebar-nav { list-style: none; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 12px 30px; color: #666; text-decoration: none; transition: all 0.2s; font-size: 15px; }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: #f8f9fa; color: #667eea; }
        .sidebar-nav i { width: 20px; text-align: center; }
        .main-content { margin-left: 260px; padding: 30px; min-height: calc(100vh - 70px); }
        .page-header { margin-bottom: 30px; }
        .page-title { font-size: 28px; font-weight: 600; color: #333; margin-bottom: 10px; }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; font-size: 14px; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-secondary { background: #f8f9fa; color: #666; border: 1px solid #e0e0e0; }
        .alert { padding: 14px 20px; border-radius: 10px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
        .alert-error { background: #fee; border: 1px solid #fcc; color: #c33; }
        .alert-success { background: #efe; border: 1px solid #cfc; color: #3c3; }
        
        .tab-container { background: white; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: 1px solid #e0e0e0; overflow: hidden; }
        .tab-navigation { display: flex; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; overflow-x: auto; position: sticky; top: 70px; z-index: 50; }
        .tab-button { padding: 18px 25px; background: transparent; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-size: 14px; font-weight: 500; color: #666; transition: all 0.3s; white-space: nowrap; display: flex; align-items: center; gap: 8px; }
        .tab-button:hover { background: rgba(102, 126, 234, 0.1); color: #667eea; }
        .tab-button.active { color: #667eea; border-bottom-color: #667eea; background: white; }
        .tab-content { display: none; padding: 30px; animation: fadeIn 0.3s; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #555; font-weight: 500; font-size: 14px; }
        input[type="text"], input[type="url"], input[type="password"], input[type="number"], select, textarea { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; font-family: inherit; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #667eea; }
        textarea { resize: vertical; min-height: 100px; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input { width: auto; accent-color: #667eea; }
        .help-text { font-size: 12px; color: #999; margin-top: 4px; }
        .info-box { background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
        .info-box h4 { color: #0066cc; margin-bottom: 8px; font-size: 14px; }
        .info-box p { color: #004d99; font-size: 13px; line-height: 1.5; }
        .section-divider { border-top: 2px solid #f0f0f0; margin: 30px 0; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .placeholder-info { background: #fff9e6; border: 1px solid #ffe066; border-radius: 8px; padding: 15px; margin: 15px 0; }
        .placeholder-info h4 { color: #996600; margin-bottom: 8px; font-size: 14px; }
        .placeholder-info code { background: #fff; padding: 2px 6px; border-radius: 3px; font-size: 12px; color: #d63384; }
        .placeholder-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-title"><i class="fas fa-shield-alt"></i> Administration</div>
        <a href="../index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
    </div>
    
    <div class="sidebar">
        <nav class="sidebar-nav">
            <ul>
                <li><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="sites.php"><i class="fas fa-map-marker-alt"></i> Sites verwalten</a></li>
                <li><a href="users.php"><i class="fas fa-users"></i> Benutzer verwalten</a></li>
                <li><a href="vouchers.php"><i class="fas fa-ticket-alt"></i> Voucher-Historie</a></li>
                <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> Einstellungen</a></li>
            </ul>
        </nav>
    </div>
    
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Einstellungen</h1>
            <p style="color: #666; font-size: 14px;">System-Konfiguration und Personalisierung</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span></div>
        <?php endif; ?>
        
        <div class="tab-container">
            <div class="tab-navigation">
                <button class="tab-button active" onclick="switchTab('general')"><i class="fas fa-sliders-h"></i> Allgemein</button>
                <button class="tab-button" onclick="switchTab('cron')"><i class="fas fa-clock"></i> Cron-Sync</button>
                <button class="tab-button" onclick="switchTab('m365')"><i class="fab fa-microsoft"></i> Microsoft 365</button>
                <button class="tab-button" onclick="switchTab('smtp')"><i class="fas fa-envelope"></i> SMTP</button>
                <button class="tab-button" onclick="switchTab('templates')"><i class="fas fa-file-alt"></i> Templates</button>
                <button class="tab-button" onclick="switchTab('system')"><i class="fas fa-cogs"></i> System</button>
                <button class="tab-button" onclick="switchTab('password')"><i class="fas fa-key"></i> Passwort</button>
            </div>
            
            <!-- TAB: Allgemein -->
            <div id="tab-general" class="tab-content active">
                <h2 style="margin-bottom: 20px;"><i class="fas fa-sliders-h"></i> Allgemeine Einstellungen</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    <input type="hidden" name="form_type" value="general">
                    
                    <div class="form-group">
                        <label for="app_title">Anwendungs-Titel *</label>
                        <input type="text" id="app_title" name="app_title" value="<?= htmlspecialchars($currentSettings['app_title']) ?>" required>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="logo_url">Logo-URL</label>
                            <input type="url" id="logo_url" name="logo_url" value="<?= htmlspecialchars($currentSettings['logo_url']) ?>" placeholder="https://example.com/logo.png">
                        </div>
                        <div class="form-group">
                            <label for="favicon_url">Favicon-URL</label>
                            <input type="url" id="favicon_url" name="favicon_url" value="<?= htmlspecialchars($currentSettings['favicon_url']) ?>" placeholder="https://example.com/favicon.ico">
                            <div class="help-text">Icon im Browser-Tab (.ico, .png, .svg)</div>
                        </div>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <div class="form-group">
                        <label for="instruction_header">Anleitung - Überschrift</label>
                        <input type="text" id="instruction_header" name="instruction_header" value="<?= htmlspecialchars($currentSettings['instruction_header']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="instruction_text">Anleitung - Text</label>
                        <textarea id="instruction_text" name="instruction_text" class="tinymce-editor"><?= htmlspecialchars($currentSettings['instruction_text']) ?></textarea>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="public_access" name="public_access" <?= $currentSettings['public_access'] == '1' ? 'checked' : '' ?>>
                        <label for="public_access" style="margin:0;">Öffentlicher Zugriff</label>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                    </div>
                </form>
            </div>
            
            <!-- TAB: Cron-Sync -->
            <div id="tab-cron" class="tab-content">
                <h2 style="margin-bottom: 20px;"><i class="fas fa-clock"></i> Automatische Voucher-Synchronisation</h2>

                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> Was macht der Cron-Job?</h4>
                    <p>Der Cron-Job synchronisiert automatisch alle Voucher von Ihren UniFi Controllern in die lokale Datenbank.
                    Dadurch werden Dashboard und Voucher-Übersicht sofort beim Öffnen angezeigt, ohne auf die API warten zu müssen.</p>
                </div>

                <div class="section-divider"></div>

                <h3 style="margin-bottom: 15px;">Cron-Token</h3>

                <?php if (empty($currentSettings['cron_token'])): ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <p style="color: #856404; margin-bottom: 15px;"><i class="fas fa-exclamation-triangle"></i> <strong>Kein Token konfiguriert.</strong> Generieren Sie einen Token, um den Cron-Job zu aktivieren.</p>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                        <button type="submit" name="generate_cron_token" class="btn btn-primary">
                            <i class="fas fa-key"></i> Token generieren
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div style="background: #d4edda; border: 1px solid #28a745; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <p style="color: #155724; margin-bottom: 10px;"><i class="fas fa-check-circle"></i> <strong>Token ist aktiv</strong></p>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 15px; font-family: monospace; word-break: break-all;">
                        <?= htmlspecialchars($currentSettings['cron_token']) ?>
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button onclick="copyToClipboard('<?= htmlspecialchars($currentSettings['cron_token']) ?>')" class="btn btn-secondary">
                            <i class="fas fa-copy"></i> Token kopieren
                        </button>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                            <button type="submit" name="generate_cron_token" class="btn btn-secondary">
                                <i class="fas fa-sync"></i> Neu generieren
                            </button>
                        </form>
                        <form method="post" style="display: inline;" onsubmit="return confirm('Token wirklich löschen? Der Cron-Job wird deaktiviert.');">
                            <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                            <button type="submit" name="delete_cron_token" class="btn btn-secondary" style="color: #dc3545;">
                                <i class="fas fa-trash"></i> Löschen
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <div class="section-divider"></div>

                <h3 style="margin-bottom: 15px;">Cron-Job einrichten</h3>

                <?php
                // Basis-Pfad aus dem bereits berechneten $scriptPath verwenden (vermeidet doppelten Slash)
                $cronUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $scriptPath . '/cron_sync.php?token=' . ($currentSettings['cron_token'] ?: 'DEIN_TOKEN');
                ?>

                <div class="form-group">
                    <label>Cron-URL (für Webhooks oder externe Aufrufe)</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="cronUrl" value="<?= htmlspecialchars($cronUrl) ?>" readonly style="flex: 1; background: #f8f9fa;">
                        <button onclick="copyToClipboard(document.getElementById('cronUrl').value)" class="btn btn-secondary">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>

                <div class="placeholder-info" style="background: #f8f9fa; border-color: #ccc;">
                    <h4 style="color: #333;">Crontab-Eintrag (Linux/Mac)</h4>
                    <p style="color: #666; margin-bottom: 10px;">Fügen Sie diese Zeile in Ihre Crontab ein (<code>crontab -e</code>):</p>
                    <div style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; font-family: monospace; font-size: 13px; overflow-x: auto;">
                        */30 * * * * curl -s "<?= htmlspecialchars($cronUrl) ?>" > /dev/null 2>&1
                    </div>
                    <p style="color: #999; font-size: 12px; margin-top: 10px;">Dies führt die Synchronisation alle 30 Minuten aus.</p>
                </div>

                <div class="placeholder-info" style="background: #f8f9fa; border-color: #ccc; margin-top: 15px;">
                    <h4 style="color: #333;">Windows Task Scheduler</h4>
                    <p style="color: #666; margin-bottom: 10px;">Erstellen Sie eine geplante Aufgabe mit diesem Befehl:</p>
                    <div style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; font-family: monospace; font-size: 13px; overflow-x: auto;">
                        powershell -Command "Invoke-WebRequest -Uri '<?= htmlspecialchars($cronUrl) ?>' -UseBasicParsing"
                    </div>
                </div>

                <div class="section-divider"></div>

                <h3 style="margin-bottom: 15px;">Status</h3>

                <table style="width: 100%; max-width: 500px;">
                    <tr>
                        <td style="padding: 10px 0; color: #666;">Letzte Synchronisation:</td>
                        <td>
                            <?php if ($currentSettings['last_cron_sync']): ?>
                                <strong><?= date('d.m.Y H:i:s', strtotime($currentSettings['last_cron_sync'])) ?></strong>
                            <?php else: ?>
                                <em style="color: #999;">Noch nie ausgeführt</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; color: #666;">Token-Status:</td>
                        <td>
                            <?php if ($currentSettings['cron_token']): ?>
                                <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Aktiv</span>
                            <?php else: ?>
                                <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Nicht konfiguriert</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php if ($currentSettings['cron_token']): ?>
                <div style="margin-top: 20px;">
                    <button onclick="testCronJob()" class="btn btn-primary" id="testCronBtn">
                        <i class="fas fa-play"></i> Jetzt manuell ausführen
                    </button>
                    <span id="testCronResult" style="margin-left: 15px;"></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- TAB: M365 -->
            <div id="tab-m365" class="tab-content">
                <h2 style="margin-bottom: 20px;"><i class="fab fa-microsoft"></i> Microsoft 365</h2>
                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> Azure AD App</h4>
                    <p>Redirect URI: <strong><?= $protocol . '://' . $host . $scriptPath ?>/m365_callback.php</strong></p>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    <input type="hidden" name="form_type" value="m365">
                    <div class="form-group">
                        <label for="m365_client_id">Client ID</label>
                        <input type="text" id="m365_client_id" name="m365_client_id" value="<?= htmlspecialchars($currentSettings['m365_client_id']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="m365_client_secret">Client Secret</label>
                        <input type="password" id="m365_client_secret" name="m365_client_secret" value="<?= htmlspecialchars($currentSettings['m365_client_secret']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="m365_tenant_id">Tenant ID</label>
                        <input type="text" id="m365_tenant_id" name="m365_tenant_id" value="<?= htmlspecialchars($currentSettings['m365_tenant_id']) ?>">
                    </div>
                    <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                </form>
            </div>
            
            <!-- TAB: SMTP -->
            <div id="tab-smtp" class="tab-content">
                <h2 style="margin-bottom: 20px;"><i class="fas fa-envelope"></i> SMTP</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    <input type="hidden" name="form_type" value="smtp">
                    <div class="checkbox-group" style="margin-bottom: 20px;">
                        <input type="checkbox" id="smtp_enabled" name="smtp_enabled" <?= $currentSettings['smtp_enabled'] == '1' ? 'checked' : '' ?>>
                        <label for="smtp_enabled" style="margin:0;">SMTP aktivieren</label>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="smtp_host">Host</label>
                            <input type="text" id="smtp_host" name="smtp_host" value="<?= htmlspecialchars($currentSettings['smtp_host']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="smtp_port">Port</label>
                            <input type="number" id="smtp_port" name="smtp_port" value="<?= htmlspecialchars($currentSettings['smtp_port']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="smtp_encryption">Verschlüsselung</label>
                        <select id="smtp_encryption" name="smtp_encryption">
                            <option value="tls" <?= $currentSettings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                            <option value="ssl" <?= $currentSettings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="none" <?= $currentSettings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>Keine</option>
                        </select>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="smtp_username">Benutzername</label>
                            <input type="text" id="smtp_username" name="smtp_username" value="<?= htmlspecialchars($currentSettings['smtp_username']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="smtp_password">Passwort</label>
                            <input type="password" id="smtp_password" name="smtp_password" value="<?= htmlspecialchars($currentSettings['smtp_password']) ?>" placeholder="Leer = nicht ändern">
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="smtp_from_email">Absender E-Mail</label>
                            <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?= htmlspecialchars($currentSettings['smtp_from_email']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="smtp_from_name">Absender Name</label>
                            <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= htmlspecialchars($currentSettings['smtp_from_name']) ?>">
                        </div>
                    </div>
                    <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                </form>
            </div>

<!-- TEIL 1 ENDET HIER - Fortsetzung in TEIL 2 -->


<!-- TEIL 2 BEGINNT HIER - Füge dies NACH Teil 1 ein -->

            <!-- TAB: Templates -->
            <div id="tab-templates" class="tab-content">
                <h2 style="margin-bottom: 20px;"><i class="fas fa-file-alt"></i> E-Mail Templates</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    <input type="hidden" name="form_type" value="templates">
                    
                    <div class="form-group">
                        <label for="system_url">System-URL</label>
                        <input type="url" id="system_url" name="system_url" value="<?= htmlspecialchars($currentSettings['system_url']) ?>" required>
                        <div class="help-text">Wird in E-Mails als Login-Link verwendet. Auto: <code><?= $autoDetectedUrl ?></code></div>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <h3 style="margin-bottom: 15px;">Voucher E-Mail</h3>
                    <div class="placeholder-info">
                        <h4>Platzhalter:</h4>
                        <div class="placeholder-list">
                            <div><code>{VOUCHER_CODE}</code></div>
                            <div><code>{SITE_NAME}</code></div>
                            <div><code>{MAX_USES}</code></div>
                            <div><code>{APP_TITLE}</code></div>
                            <div><code>{INSTRUCTIONS}</code></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email_voucher_subject">Betreff</label>
                        <input type="text" id="email_voucher_subject" name="email_voucher_subject" value="<?= htmlspecialchars($currentSettings['email_voucher_subject']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email_voucher_body">E-Mail Text</label>
                        <textarea id="email_voucher_body" name="email_voucher_body" class="tinymce-editor"><?= htmlspecialchars($currentSettings['email_voucher_body']) ?></textarea>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <h3 style="margin-bottom: 15px;">Benutzer-Benachrichtigung</h3>
                    <div class="placeholder-info">
                        <h4>Platzhalter:</h4>
                        <div class="placeholder-list">
                            <div><code>{USER_NAME}</code></div>
                            <div><code>{CHANGES}</code></div>
                            <div><code>{APP_TITLE}</code></div>
                            <div><code>{SYSTEM_URL}</code></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email_user_notification_subject">Betreff</label>
                        <input type="text" id="email_user_notification_subject" name="email_user_notification_subject" value="<?= htmlspecialchars($currentSettings['email_user_notification_subject']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email_user_notification_body">E-Mail Text</label>
                        <textarea id="email_user_notification_body" name="email_user_notification_body" class="tinymce-editor"><?= htmlspecialchars($currentSettings['email_user_notification_body']) ?></textarea>
                    </div>
                    
                    <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                </form>
            </div>
            
            <!-- TAB: System -->
            <div id="tab-system" class="tab-content">
                <h2 style="margin-bottom: 20px;"><i class="fas fa-cogs"></i> System & Erweitert</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    <input type="hidden" name="form_type" value="system">
                    
                    <div class="info-box">
                        <h4><i class="fas fa-info-circle"></i> TinyMCE API Key</h4>
                        <p>Kostenlosen API Key erhalten: <a href="https://www.tiny.cloud/auth/signup/" target="_blank" style="color: #0066cc;">tiny.cloud/signup</a><br>
                        Ohne Key wird eine eingeschränkte Version geladen.</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="tinymce_api_key">TinyMCE API Key (optional)</label>
                        <input type="text" id="tinymce_api_key" name="tinymce_api_key" value="<?= htmlspecialchars($currentSettings['tinymce_api_key']) ?>" placeholder="your-api-key-here">
                        <div class="help-text">Für WYSIWYG-Editor in Anleitungen und E-Mail-Templates</div>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <h3 style="margin-bottom: 15px;">Druck-Template</h3>
                    <div class="placeholder-info">
                        <h4>Platzhalter:</h4>
                        <div class="placeholder-list">
                            <div><code>{VOUCHER_CODE}</code></div>
                            <div><code>{EXPIRY_DATE}</code></div>
                            <div><code>{EXPIRY_TIME}</code></div>
                            <div><code>{SITE_NAME}</code></div>
                            <div><code>{MAX_USES}</code></div>
                            <div><code>{APP_TITLE}</code></div>
                            <div><code>{INSTRUCTIONS}</code></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="print_template">HTML Template für Voucher-Druck</label>
                        <textarea id="print_template" name="print_template" class="tinymce-editor" style="min-height: 300px;"><?= htmlspecialchars($currentSettings['print_template']) ?></textarea>
                        <div class="help-text">HTML/CSS-Code für den Ausdruck von Vouchers</div>
                    </div>
                    
                    <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                </form>
                
                <div class="section-divider"></div>
                
                <h3 style="margin-bottom: 15px;">System-Information</h3>
                <table style="width: 100%;">
                    <tr><td style="padding: 10px 0; color: #666;">PHP Version:</td><td><strong><?= phpversion() ?></strong></td></tr>
                    <tr><td style="padding: 10px 0; color: #666;">Datenbank:</td><td><strong><?= DB_NAME ?></strong></td></tr>
                    <tr><td style="padding: 10px 0; color: #666;">Installiert:</td><td><strong><?= date('d.m.Y H:i', filectime(__DIR__ . '/../config.php')) ?></strong></td></tr>
                    <tr><td style="padding: 10px 0; color: #666;">Version:</td><td><strong>2.0.0</strong></td></tr>
                </table>
            </div>
            
            <!-- TAB: Passwort -->
            <div id="tab-password" class="tab-content">
                <h2 style="margin-bottom: 20px;"><i class="fas fa-key"></i> Passwort ändern</h2>
                <form method="post" style="max-width: 500px;">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    
                    <div class="form-group">
                        <label for="current_password">Aktuelles Passwort</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">Neues Passwort</label>
                        <input type="password" id="new_password" name="new_password" required minlength="8">
                        <div class="help-text">Mindestens 8 Zeichen</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Passwort bestätigen</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" name="change_password" class="btn btn-primary"><i class="fas fa-lock"></i> Passwort ändern</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Tab-Switching
        function switchTab(tabName) {
            // Alle Tabs ausblenden
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Alle Tab-Buttons deaktivieren
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Gewählten Tab aktivieren
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
            
            // URL Hash aktualisieren (optional)
            window.location.hash = tabName;
        }
        
        // Tab aus URL Hash laden (beim Seitenladen)
        window.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash.substring(1);
            if (hash && document.getElementById('tab-' + hash)) {
                const tabButton = Array.from(document.querySelectorAll('.tab-button')).find(btn => 
                    btn.textContent.toLowerCase().includes(hash)
                );
                if (tabButton) {
                    tabButton.click();
                }
            }
            
            // TinyMCE initialisieren
            initTinyMCE();
        });
        
        // In Zwischenablage kopieren
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('In die Zwischenablage kopiert!');
            }).catch(err => {
                // Fallback für ältere Browser
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('In die Zwischenablage kopiert!');
            });
        }

        // Cron-Job testen
        async function testCronJob() {
            const btn = document.getElementById('testCronBtn');
            const result = document.getElementById('testCronResult');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Läuft...';
            result.innerHTML = '';

            try {
                const response = await fetch('../cron_sync.php?token=<?= htmlspecialchars($currentSettings['cron_token']) ?>');
                const data = await response.json();

                if (data.success) {
                    result.innerHTML = '<span style="color: #28a745;"><i class="fas fa-check-circle"></i> ' + data.message + '</span>';
                    // Seite nach 2 Sekunden neu laden, um Status zu aktualisieren
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    result.innerHTML = '<span style="color: #dc3545;"><i class="fas fa-times-circle"></i> ' + data.message + '</span>';
                }
            } catch (error) {
                result.innerHTML = '<span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Fehler: ' + error.message + '</span>';
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-play"></i> Jetzt manuell ausführen';
        }

        // TinyMCE WYSIWYG Editor initialisieren
        function initTinyMCE() {
            tinymce.init({
                selector: '.tinymce-editor',
                height: 400,
                menubar: false,
                plugins: [
                    'advlist', 'autolink', 'lists', 'link', 'charmap',
                    'searchreplace', 'visualblocks', 'code', 'fullscreen',
                    'insertdatetime', 'table', 'help', 'wordcount'
                ],
                toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright | bullist numlist | removeformat | help',
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.6; }',
                branding: false,
                promotion: false
            });
        }
    </script>
</body>
</html>