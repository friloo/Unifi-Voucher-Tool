<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../includes/I18n.php';
require_once __DIR__ . '/../includes/Helpers.php';

$auth = new Auth();
$auth->requireAdmin();
I18n::init();

$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

// AJAX: SMTP-Test-E-Mail senden
if (isset($_POST['ajax_smtp_test'])) {
    header('Content-Type: application/json');
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => __('error_csrf')]);
        exit;
    }
    $to = trim($_POST['test_email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => __('error_email_invalid')]);
        exit;
    }
    $mailer = new Mailer();
    $ok = $mailer->sendTestEmail($to);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? "Test-E-Mail wurde an {$to} gesendet." : 'Versand fehlgeschlagen. Prüfen Sie die SMTP-Einstellungen.'
    ]);
    exit;
}

$error = '';
$success = '';

// Einstellungen speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        try {
            $settings = [];
            $formType = $_POST['form_type'] ?? '';

            if ($formType === 'general') {
                $settings['app_title'] = trim($_POST['app_title'] ?? '');
                $settings['logo_url'] = trim($_POST['logo_url'] ?? '');
                $settings['favicon_url'] = trim($_POST['favicon_url'] ?? '');
                $settings['instruction_header'] = trim($_POST['instruction_header'] ?? '');
                $settings['instruction_text'] = $_POST['instruction_text'] ?? '';
                $settings['public_access'] = isset($_POST['public_access']) ? '1' : '0';
            }

            if ($formType === 'defaults') {
                $expMin = (int)($_POST['default_expire_minutes'] ?? 480);
                $defDev = (int)($_POST['default_max_uses'] ?? 1);
                $maxDev = (int)($_POST['max_uses_limit'] ?? 10);
                if ($expMin < 1) $expMin = 480;
                if ($defDev < 1) $defDev = 1;
                if ($maxDev < 1) $maxDev = 10;
                $settings['default_expire_minutes'] = (string)$expMin;
                $settings['default_max_uses'] = (string)$defDev;
                $settings['max_uses_limit'] = (string)$maxDev;
            }

            if ($formType === 'm365') {
                $settings['m365_client_id'] = trim($_POST['m365_client_id'] ?? '');
                // Secret nur aktualisieren, wenn eines eingegeben wurde – es wird
                // (wie das SMTP-Passwort) nicht mehr ins Formular zurueckgegeben.
                if (!empty($_POST['m365_client_secret'])) {
                    $settings['m365_client_secret'] = trim($_POST['m365_client_secret']);
                }
                $settings['m365_tenant_id'] = trim($_POST['m365_tenant_id'] ?? '');
            }

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

            if ($formType === 'templates') {
                $settings['email_voucher_subject'] = trim($_POST['email_voucher_subject'] ?? '');
                $settings['email_voucher_body'] = $_POST['email_voucher_body'] ?? '';
                $settings['email_user_notification_subject'] = trim($_POST['email_user_notification_subject'] ?? '');
                $settings['email_user_notification_body'] = $_POST['email_user_notification_body'] ?? '';
                $settings['system_url'] = trim($_POST['system_url'] ?? '');
            }

            if ($formType === 'system') {
                $settings['tinymce_api_key'] = trim($_POST['tinymce_api_key'] ?? '');
                $settings['print_template'] = $_POST['print_template'] ?? '';
            }

            foreach ($settings as $key => $value) {
                $db->setSetting($key, $value);
            }

            // PRG + Tab-Anker: F5 speichert nicht erneut, und der Nutzer landet
            // wieder auf dem Tab, in dem er gespeichert hat.
            $tabAnchors = [
                'general' => 'general', 'defaults' => 'defaults', 'm365' => 'm365',
                'smtp' => 'smtp', 'templates' => 'templates_email', 'system' => 'system',
            ];
            flashSet(__('settings_saved'));
            header('Location: settings.php#' . ($tabAnchors[$formType] ?? 'general'));
            exit;
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// Cron-Token generieren/löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_cron_token'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        $db->setSetting('cron_token', bin2hex(random_bytes(32)));
        flashSet(__('cron_token_generated'));
        header('Location: settings.php#cron');
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cron_token'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        $db->setSetting('cron_token', '');
        flashSet(__('cron_token_deleted'));
        header('Location: settings.php#cron');
        exit;
    }
}

// Passwort ändern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        try {
            $user = $auth->getCurrentUser();
            if (!password_verify($_POST['current_password'], $user['password_hash'])) {
                throw new Exception(__('error_pw_current'));
            }
            if (strlen($_POST['new_password']) < 8) {
                throw new Exception(__('settings_pw_minlength'));
            }
            if ($_POST['new_password'] !== $_POST['confirm_password']) {
                throw new Exception(__('error_pw_mismatch'));
            }
            $db->query("UPDATE users SET password_hash = ? WHERE id = ?",
                [password_hash($_POST['new_password'], PASSWORD_DEFAULT), $user['id']]);
            flashSet(__('settings_pw_changed'));
            header('Location: settings.php#password');
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if (empty($success) && empty($error) && ($flash = flashGet())) {
    $success = $flash['message'];
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['SCRIPT_NAME'], 2);
$scriptPath = $scriptPath === '/' ? '' : $scriptPath;
$autoDetectedUrl = $protocol . '://' . $host . $scriptPath;

$cs = [
    'app_title'                    => $db->getSetting('app_title', 'UniFi Voucher System'),
    'logo_url'                     => $db->getSetting('logo_url', ''),
    'favicon_url'                  => $db->getSetting('favicon_url', ''),
    'instruction_header'           => $db->getSetting('instruction_header', 'So verwenden Sie Ihren Code'),
    'instruction_text'             => $db->getSetting('instruction_text', ''),
    'public_access'                => $db->getSetting('public_access', '0'),
    'default_expire_minutes'       => $db->getSetting('default_expire_minutes', '480'),
    'default_max_uses'             => $db->getSetting('default_max_uses', '1'),
    'max_uses_limit'               => $db->getSetting('max_uses_limit', '10'),
    'm365_client_id'               => $db->getSetting('m365_client_id', ''),
    'm365_client_secret'           => $db->getSetting('m365_client_secret', ''),
    'm365_tenant_id'               => $db->getSetting('m365_tenant_id', ''),
    'smtp_enabled'                 => $db->getSetting('smtp_enabled', '0'),
    'smtp_host'                    => $db->getSetting('smtp_host', ''),
    'smtp_port'                    => $db->getSetting('smtp_port', '587'),
    'smtp_username'                => $db->getSetting('smtp_username', ''),
    'smtp_password'                => $db->getSetting('smtp_password', ''),
    'smtp_encryption'              => $db->getSetting('smtp_encryption', 'tls'),
    'smtp_from_email'              => $db->getSetting('smtp_from_email', ''),
    'smtp_from_name'               => $db->getSetting('smtp_from_name', ''),
    'system_url'                   => $db->getSetting('system_url', $autoDetectedUrl),
    'email_voucher_subject'        => $db->getSetting('email_voucher_subject', '{APP_TITLE} - Ihr WLAN-Zugang'),
    'email_voucher_body'           => $db->getSetting('email_voucher_body', "Hallo,\n\nIhr Code: {VOUCHER_CODE}\n\nGültigkeit: 8h\nGeräte: {MAX_USES}\nSite: {SITE_NAME}"),
    'email_user_notification_subject' => $db->getSetting('email_user_notification_subject', '{APP_TITLE} - Berechtigungen geändert'),
    'email_user_notification_body' => $db->getSetting('email_user_notification_body', "Hallo {USER_NAME},\n\n{CHANGES}"),
    'tinymce_api_key'              => $db->getSetting('tinymce_api_key', ''),
    'print_template'               => $db->getSetting('print_template', '<div style="text-align:center;padding:40px"><h1>{APP_TITLE}</h1><h2>WLAN Code</h2><div style="font-size:48px;font-weight:bold;margin:30px 0;font-family:monospace">{VOUCHER_CODE}</div><p><strong>Gültig bis:</strong> {EXPIRY_DATE} {EXPIRY_TIME}</p><p><strong>Site:</strong> {SITE_NAME}</p><p><strong>Geräte:</strong> {MAX_USES}</p><hr style="margin:30px 0"><div>{INSTRUCTIONS}</div></div>'),
    'cron_token'                   => $db->getSetting('cron_token', ''),
    'last_cron_sync'               => $db->getSetting('last_cron_sync', ''),
];

$currentPage = 'settings';
$adminBase = '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('settings_title') ?> - <?= htmlspecialchars($appTitle) ?></title>

    <?php if (!empty($cs['tinymce_api_key'])): ?>
    <script src="https://cdn.tiny.cloud/1/<?= htmlspecialchars($cs['tinymce_api_key']) ?>/tinymce/6/tinymce.min.js"></script>
    <?php else: ?>
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js"></script>
    <?php endif; ?>

    <?php include __DIR__ . '/../includes/admin_nav.php'; ?>

    <style>
        .page-header { margin-bottom: 30px; }
        .page-title  { font-size: 28px; font-weight: 600; color: var(--text-primary); margin-bottom: 8px; }
        .tab-container { background: var(--bg-card); border-radius: 15px; box-shadow: 0 2px 10px var(--shadow); border: 1px solid var(--border-color); overflow: hidden; }
        .tab-navigation { display: flex; background: var(--bg-table-head); border-bottom: 2px solid var(--border-color); overflow-x: auto; position: sticky; top: 70px; z-index: 50; }
        .tab-button { padding: 15px 20px; background: transparent; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-size: 13px; font-weight: 500; color: var(--text-secondary); transition: all 0.3s; white-space: nowrap; display: flex; align-items: center; gap: 7px; }
        .tab-button:hover { background: rgba(102,126,234,0.1); color: var(--accent); }
        .tab-button.active { color: var(--accent); border-bottom-color: var(--accent); background: var(--bg-card); }
        .tab-content { display: none; padding: 30px; animation: fadeIn 0.2s; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 7px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
        input[type="text"], input[type="url"], input[type="password"], input[type="number"], input[type="email"], select, textarea { width: 100%; padding: 11px 14px; border: 2px solid var(--border-color); border-radius: 8px; font-size: 14px; transition: border-color 0.2s; font-family: inherit; background: var(--bg-input); color: var(--text-primary); }
        input:focus, textarea:focus, select:focus { outline: none; border-color: var(--accent); }
        textarea { resize: vertical; min-height: 100px; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input { width: auto; accent-color: var(--accent); }
        .help-text { font-size: 12px; color: var(--text-muted); margin-top: 5px; }
        .info-box { background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
        .info-box h4 { color: #0066cc; margin-bottom: 8px; font-size: 14px; }
        .info-box p { color: #004d99; font-size: 13px; line-height: 1.5; }
        .section-divider { border: none; border-top: 2px solid var(--border-color); margin: 28px 0; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; }
        .placeholder-info { background: #fff9e6; border: 1px solid #ffe066; border-radius: 8px; padding: 14px; margin: 14px 0; }
        .placeholder-info h4 { color: #996600; margin-bottom: 8px; font-size: 14px; }
        .placeholder-info code { background: #fff; padding: 2px 6px; border-radius: 3px; font-size: 12px; color: #d63384; }
        .placeholder-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-danger { background: #dc3545; color: white; }
        .btn-small { padding: 6px 12px; font-size: 13px; }
        .token-box { background: var(--bg-hover); padding: 14px; border-radius: 6px; font-family: monospace; word-break: break-all; color: var(--text-primary); }
        code { background: var(--code-bg); color: var(--accent); padding: 2px 6px; border-radius: 4px; font-size: 13px; }
    </style>
</head>

    <div class="page-header">
        <h1 class="page-title"><?= __('settings_title') ?></h1>
        <p style="color: var(--text-muted); font-size: 14px;"><?= __('settings_subtitle') ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span></div>
    <?php endif; ?>

    <div class="tab-container">
        <div class="tab-navigation" id="tabNav">
            <button class="tab-button active" data-tab="general"><i class="fas fa-sliders-h"></i> <?= __('settings_tab_general') ?></button>
            <button class="tab-button" data-tab="defaults"><i class="fas fa-sliders-h"></i> <?= __('settings_tab_defaults') ?></button>
            <button class="tab-button" data-tab="cron"><i class="fas fa-clock"></i> <?= __('settings_tab_cron') ?></button>
            <button class="tab-button" data-tab="m365"><i class="fab fa-microsoft"></i> <?= __('settings_tab_m365') ?></button>
            <button class="tab-button" data-tab="smtp"><i class="fas fa-envelope"></i> <?= __('settings_tab_smtp') ?></button>
            <button class="tab-button" data-tab="templates_email"><i class="fas fa-file-alt"></i> <?= __('settings_tab_templates_email') ?></button>
            <button class="tab-button" data-tab="system"><i class="fas fa-cogs"></i> <?= __('settings_tab_system') ?></button>
            <button class="tab-button" data-tab="password"><i class="fas fa-key"></i> <?= __('settings_tab_password') ?></button>
        </div>

        <!-- Allgemein -->
        <div id="tab-general" class="tab-content active">
            <h2 style="margin-bottom: 20px; color: var(--text-primary);"><i class="fas fa-sliders-h"></i> <?= __('settings_tab_general') ?></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="general">
                <div class="form-group"><label><?= __('settings_app_title') ?></label><input type="text" name="app_title" value="<?= htmlspecialchars($cs['app_title']) ?>" required></div>
                <div class="form-grid">
                    <div class="form-group"><label><?= __('settings_logo_url') ?></label><input type="url" name="logo_url" value="<?= htmlspecialchars($cs['logo_url']) ?>" placeholder="https://example.com/logo.png"></div>
                    <div class="form-group"><label><?= __('settings_favicon_url') ?></label><input type="url" name="favicon_url" value="<?= htmlspecialchars($cs['favicon_url']) ?>" placeholder="https://example.com/favicon.ico"><div class="help-text"><?= __('settings_favicon_hint') ?></div></div>
                </div>
                <hr class="section-divider">
                <div class="form-group"><label><?= __('settings_instr_header') ?></label><input type="text" name="instruction_header" value="<?= htmlspecialchars($cs['instruction_header']) ?>"></div>
                <div class="form-group"><label><?= __('settings_instr_text') ?></label><textarea name="instruction_text" class="tinymce-editor"><?= htmlspecialchars($cs['instruction_text']) ?></textarea></div>
                <div class="checkbox-group" style="margin-bottom: 20px;"><input type="checkbox" name="public_access" id="public_access" <?= $cs['public_access'] == '1' ? 'checked' : '' ?>><label for="public_access" style="margin:0;"><?= __('settings_public_access') ?></label></div>
                <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> <?= __('btn_save') ?></button>
            </form>
        </div>

        <!-- Voucher-Standards -->
        <div id="tab-defaults" class="tab-content">
            <h2 style="margin-bottom: 8px; color: var(--text-primary);"><i class="fas fa-sliders-h"></i> <?= __('settings_tab_defaults') ?></h2>
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 24px;">Diese Werte werden als Vorgabe im Voucher-Formular verwendet.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="defaults">
                <div class="form-grid">
                    <div class="form-group">
                        <label><?= __('settings_default_expire') ?></label>
                        <input type="number" name="default_expire_minutes" value="<?= (int)$cs['default_expire_minutes'] ?>" min="1" max="525600">
                        <div class="help-text"><?= __('settings_default_expire_hint') ?></div>
                    </div>
                    <div class="form-group">
                        <label><?= __('settings_default_devices') ?></label>
                        <input type="number" name="default_max_uses" value="<?= (int)$cs['default_max_uses'] ?>" min="1" max="100">
                        <div class="help-text"><?= __('settings_default_devices_hint') ?></div>
                    </div>
                    <div class="form-group">
                        <label><?= __('settings_max_devices') ?></label>
                        <input type="number" name="max_uses_limit" value="<?= (int)$cs['max_uses_limit'] ?>" min="1" max="100">
                        <div class="help-text"><?= __('settings_max_devices_hint') ?></div>
                    </div>
                </div>
                <div class="info-box" style="margin-top: 10px;">
                    <h4><i class="fas fa-info-circle"></i> Gültigkeits-Referenz</h4>
                    <p>
                        60 Min = 1 Stunde &nbsp;|&nbsp; 480 Min = 8 Stunden &nbsp;|&nbsp;
                        1440 Min = 1 Tag &nbsp;|&nbsp; 10080 Min = 1 Woche &nbsp;|&nbsp;
                        43200 Min = 30 Tage
                    </p>
                </div>
                <button type="submit" name="save_settings" class="btn btn-primary" style="margin-top: 16px;"><i class="fas fa-save"></i> <?= __('btn_save') ?></button>
            </form>
        </div>

        <!-- Cron-Sync -->
        <div id="tab-cron" class="tab-content">
            <h2 style="margin-bottom: 20px; color: var(--text-primary);"><i class="fas fa-clock"></i> <?= __('settings_tab_cron') ?></h2>
            <div class="info-box">
                <h4><i class="fas fa-info-circle"></i> Was macht der Cron-Job?</h4>
                <p>Der Cron-Job synchronisiert automatisch alle Voucher von Ihren UniFi Controllern in die lokale Datenbank.</p>
            </div>
            <hr class="section-divider">
            <?php if (empty($cs['cron_token'])): ?>
                <p style="color: var(--text-muted); margin-bottom: 15px;"><i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i> Kein Token konfiguriert.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    <button type="submit" name="generate_cron_token" class="btn btn-primary"><i class="fas fa-key"></i> Token generieren</button>
                </form>
            <?php else: ?>
                <div class="token-box" style="margin-bottom: 15px;"><?= htmlspecialchars($cs['cron_token']) ?></div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                    <button onclick="copyToClipboard('<?= htmlspecialchars($cs['cron_token']) ?>')" class="btn btn-secondary"><i class="fas fa-copy"></i> Kopieren</button>
                    <form method="post" style="display:inline;"><input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>"><button type="submit" name="generate_cron_token" class="btn btn-secondary"><i class="fas fa-sync"></i> Neu generieren</button></form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('<?= addslashes(__('confirm_delete_token')) ?>');"><input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>"><button type="submit" name="delete_cron_token" class="btn btn-secondary" style="color: var(--danger);"><i class="fas fa-trash"></i> Löschen</button></form>
                </div>
            <?php endif; ?>
            <?php
            $cronUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $scriptPath . '/cron_sync.php?token=' . ($cs['cron_token'] ?: 'DEIN_TOKEN');
            ?>
            <div class="form-group">
                <label>Cron-URL</label>
                <div style="display:flex;gap:10px;">
                    <input type="text" id="cronUrl" value="<?= htmlspecialchars($cronUrl) ?>" readonly>
                    <button onclick="copyToClipboard(document.getElementById('cronUrl').value)" class="btn btn-secondary"><i class="fas fa-copy"></i></button>
                </div>
            </div>
            <div class="placeholder-info" style="background: var(--bg-hover); border-color: var(--border-color);">
                <h4 style="color: var(--text-primary);">Crontab-Eintrag (alle 30 Min)</h4>
                <div style="background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 13px; margin-top: 8px; overflow-x: auto;">
                    */30 * * * * curl -s "<?= htmlspecialchars($cronUrl) ?>" &gt; /dev/null 2&gt;&amp;1
                </div>
            </div>
            <?php if ($cs['cron_token']): ?>
            <div style="margin-top: 20px;">
                <button onclick="testCronJob()" class="btn btn-primary" id="testCronBtn"><i class="fas fa-play"></i> Jetzt ausführen</button>
                <span id="testCronResult" style="margin-left: 15px;"></span>
            </div>
            <?php endif; ?>
            <p style="margin-top:20px; color: var(--text-muted); font-size: 13px;">Letzte Synchronisation: <strong><?= $cs['last_cron_sync'] ? date('d.m.Y H:i:s', strtotime($cs['last_cron_sync'])) : 'Noch nie' ?></strong></p>
        </div>

        <!-- M365 -->
        <div id="tab-m365" class="tab-content">
            <h2 style="margin-bottom: 20px; color: var(--text-primary);"><i class="fab fa-microsoft"></i> Microsoft 365</h2>
            <div class="info-box">
                <h4><i class="fas fa-info-circle"></i> Azure AD App</h4>
                <p>Redirect URI: <strong><?= $protocol . '://' . $host . $scriptPath ?>/m365_callback.php</strong></p>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="m365">
                <div class="form-group"><label>Client ID</label><input type="text" name="m365_client_id" value="<?= htmlspecialchars($cs['m365_client_id']) ?>"></div>
                <div class="form-group"><label>Client Secret</label><input type="password" name="m365_client_secret" placeholder="<?= $cs['m365_client_secret'] !== '' ? '••••••••' : '' ?>"><div class="help-text"><?= __('m365_secret_hint') ?></div></div>
                <div class="form-group"><label>Tenant ID</label><input type="text" name="m365_tenant_id" value="<?= htmlspecialchars($cs['m365_tenant_id']) ?>"></div>
                <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> <?= __('btn_save') ?></button>
            </form>
        </div>

        <!-- SMTP -->
        <div id="tab-smtp" class="tab-content">
            <h2 style="margin-bottom: 20px; color: var(--text-primary);"><i class="fas fa-envelope"></i> SMTP</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="smtp">
                <div class="checkbox-group" style="margin-bottom: 20px;"><input type="checkbox" name="smtp_enabled" id="smtp_enabled" <?= $cs['smtp_enabled'] == '1' ? 'checked' : '' ?>><label for="smtp_enabled" style="margin:0;">SMTP aktivieren</label></div>
                <div class="form-grid">
                    <div class="form-group"><label>Host</label><input type="text" name="smtp_host" value="<?= htmlspecialchars($cs['smtp_host']) ?>"></div>
                    <div class="form-group"><label>Port</label><input type="number" name="smtp_port" value="<?= htmlspecialchars($cs['smtp_port']) ?>"></div>
                </div>
                <div class="form-group"><label>Verschlüsselung</label><select name="smtp_encryption"><option value="tls" <?= $cs['smtp_encryption']==='tls'?'selected':'' ?>>TLS</option><option value="ssl" <?= $cs['smtp_encryption']==='ssl'?'selected':'' ?>>SSL</option><option value="none" <?= $cs['smtp_encryption']==='none'?'selected':'' ?>>Keine</option></select></div>
                <div class="form-grid">
                    <div class="form-group"><label>Benutzername</label><input type="text" name="smtp_username" value="<?= htmlspecialchars($cs['smtp_username']) ?>"></div>
                    <div class="form-group"><label>Passwort</label><input type="password" name="smtp_password" placeholder="Leer = nicht ändern"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Absender E-Mail</label><input type="email" name="smtp_from_email" value="<?= htmlspecialchars($cs['smtp_from_email']) ?>"></div>
                    <div class="form-group"><label>Absender Name</label><input type="text" name="smtp_from_name" value="<?= htmlspecialchars($cs['smtp_from_name']) ?>"></div>
                </div>
                <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> <?= __('btn_save') ?></button>
            </form>
            <hr class="section-divider">
            <h3 style="margin-bottom:15px; color: var(--text-primary);">SMTP testen</h3>
            <div style="display:flex;gap:10px;align-items:flex-end;">
                <div style="flex:1;"><label>Test-E-Mail senden an</label><input type="email" id="smtpTestEmail" placeholder="empfaenger@example.com" style="margin-top:6px;"></div>
                <button onclick="testSmtp()" class="btn btn-secondary" id="smtpTestBtn"><i class="fas fa-paper-plane"></i> <?= __('btn_test') ?></button>
            </div>
            <span id="smtpTestResult" style="display:block;margin-top:10px;font-size:13px;"></span>
        </div>

        <!-- E-Mail Templates -->
        <div id="tab-templates_email" class="tab-content">
            <h2 style="margin-bottom: 20px; color: var(--text-primary);"><i class="fas fa-file-alt"></i> E-Mail Templates</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="templates">
                <div class="form-group"><label>System-URL</label><input type="url" name="system_url" value="<?= htmlspecialchars($cs['system_url']) ?>"><div class="help-text">Auto: <code><?= $autoDetectedUrl ?></code></div></div>
                <hr class="section-divider">
                <h3 style="margin-bottom:15px; color: var(--text-primary);">Voucher E-Mail</h3>
                <div class="placeholder-info"><h4>Platzhalter:</h4><div class="placeholder-list"><code>{VOUCHER_CODE}</code><code>{SITE_NAME}</code><code>{MAX_USES}</code><code>{APP_TITLE}</code><code>{INSTRUCTIONS}</code></div></div>
                <div class="form-group"><label>Betreff</label><input type="text" name="email_voucher_subject" value="<?= htmlspecialchars($cs['email_voucher_subject']) ?>"></div>
                <div class="form-group"><label>E-Mail Text</label><textarea name="email_voucher_body" class="tinymce-editor"><?= htmlspecialchars($cs['email_voucher_body']) ?></textarea></div>
                <hr class="section-divider">
                <h3 style="margin-bottom:15px; color: var(--text-primary);">Benutzer-Benachrichtigung</h3>
                <div class="placeholder-info"><h4>Platzhalter:</h4><div class="placeholder-list"><code>{USER_NAME}</code><code>{CHANGES}</code><code>{APP_TITLE}</code><code>{SYSTEM_URL}</code></div></div>
                <div class="form-group"><label>Betreff</label><input type="text" name="email_user_notification_subject" value="<?= htmlspecialchars($cs['email_user_notification_subject']) ?>"></div>
                <div class="form-group"><label>E-Mail Text</label><textarea name="email_user_notification_body" class="tinymce-editor"><?= htmlspecialchars($cs['email_user_notification_body']) ?></textarea></div>
                <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> <?= __('btn_save') ?></button>
            </form>
        </div>

        <!-- System -->
        <div id="tab-system" class="tab-content">
            <h2 style="margin-bottom: 20px; color: var(--text-primary);"><i class="fas fa-cogs"></i> System & Erweitert</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="system">
                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> TinyMCE API Key</h4>
                    <p>Kostenlosen API Key: <a href="https://www.tiny.cloud/auth/signup/" target="_blank" rel="noopener" style="color:#0066cc;">tiny.cloud/signup</a></p>
                </div>
                <div class="form-group"><label>TinyMCE API Key</label><input type="text" name="tinymce_api_key" value="<?= htmlspecialchars($cs['tinymce_api_key']) ?>" placeholder="your-api-key-here"><div class="help-text">Für WYSIWYG-Editor in Anleitungen</div></div>
                <hr class="section-divider">
                <h3 style="margin-bottom:15px; color: var(--text-primary);">Druck-Template</h3>
                <div class="placeholder-info"><h4>Platzhalter:</h4><div class="placeholder-list"><code>{VOUCHER_CODE}</code><code>{EXPIRY_DATE}</code><code>{EXPIRY_TIME}</code><code>{SITE_NAME}</code><code>{MAX_USES}</code><code>{APP_TITLE}</code><code>{INSTRUCTIONS}</code></div></div>
                <div class="form-group"><label>HTML Template für Voucher-Druck</label><textarea name="print_template" class="tinymce-editor" style="min-height:250px;"><?= htmlspecialchars($cs['print_template']) ?></textarea></div>
                <button type="submit" name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> <?= __('btn_save') ?></button>
            </form>
            <hr class="section-divider">
            <h3 style="margin-bottom:15px; color: var(--text-primary);">System-Information</h3>
            <table style="width:100%; font-size:14px;">
                <tr><td style="padding:8px 0;color:var(--text-muted);">PHP Version:</td><td><strong><?= phpversion() ?></strong></td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted);">Datenbank:</td><td><strong><?= DB_NAME ?></strong></td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted);">Version:</td><td><strong>2.1.0</strong></td></tr>
            </table>
        </div>

        <!-- Passwort -->
        <div id="tab-password" class="tab-content">
            <h2 style="margin-bottom: 20px; color: var(--text-primary);"><i class="fas fa-key"></i> <?= __('settings_tab_password') ?></h2>
            <form method="post" style="max-width:500px;">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <div class="form-group"><label><?= __('settings_pw_current') ?></label><input type="password" name="current_password" required></div>
                <div class="form-group"><label><?= __('settings_pw_new') ?></label><input type="password" name="new_password" required minlength="8"><div class="help-text"><?= __('settings_pw_minlength') ?></div></div>
                <div class="form-group"><label><?= __('settings_pw_confirm') ?></label><input type="password" name="confirm_password" required></div>
                <button type="submit" name="change_password" class="btn btn-primary"><i class="fas fa-lock"></i> <?= __('settings_tab_password') ?></button>
            </form>
        </div>
    </div>

</div><!-- main-content -->

<script src="../assets/global.js"></script>
<script>
// Tab switching
document.querySelectorAll('.tab-button').forEach(btn => {
    btn.addEventListener('click', function() {
        const tab = this.dataset.tab;
        document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + tab).classList.add('active');
        location.hash = tab;
    });
});

// Restore tab from hash
window.addEventListener('DOMContentLoaded', function() {
    const hash = location.hash.substring(1);
    if (hash) {
        const btn = document.querySelector(`[data-tab="${hash}"]`);
        if (btn) btn.click();
    }
    initTinyMCE();
});

async function testSmtp() {
    const email = document.getElementById('smtpTestEmail').value.trim();
    const btn = document.getElementById('smtpTestBtn');
    const result = document.getElementById('smtpTestResult');
    if (!email) { result.textContent = 'Bitte E-Mail eingeben.'; return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('ajax_smtp_test', '1');
    fd.append('csrf_token', '<?= $auth->getCsrfToken() ?>');
    fd.append('test_email', email);
    const res = await fetch('settings.php', { method: 'POST', body: fd });
    const data = await res.json();
    result.textContent = data.message;
    result.style.color = data.success ? 'var(--success)' : 'var(--danger)';
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Testen';
}

async function testCronJob() {
    const btn = document.getElementById('testCronBtn');
    const result = document.getElementById('testCronResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Läuft...';
    try {
        const res = await fetch('../cron_sync.php?token=<?= htmlspecialchars($cs['cron_token']) ?>');
        const data = await res.json();
        result.innerHTML = data.success
            ? `<span style="color:var(--success)"><i class="fas fa-check-circle"></i> ${data.message}</span>`
            : `<span style="color:var(--danger)"><i class="fas fa-times-circle"></i> ${data.message}</span>`;
        if (data.success) showToast('success', 'Cron ausgeführt', data.message);
    } catch (e) {
        result.innerHTML = `<span style="color:var(--danger)">Fehler: ${e.message}</span>`;
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-play"></i> Jetzt ausführen';
}

function initTinyMCE() {
    tinymce.init({
        selector: '.tinymce-editor',
        height: 350,
        menubar: false,
        plugins: ['advlist','autolink','lists','link','searchreplace','visualblocks','code','fullscreen','table','help','wordcount'],
        toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright | bullist numlist | removeformat | help',
        content_style: 'body { font-family: -apple-system, sans-serif; font-size: 14px; line-height: 1.6; }',
        branding: false, promotion: false
    });
}
</script>
</body>
</html>
