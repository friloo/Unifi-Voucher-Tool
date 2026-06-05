<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Notifier.php';
require_once __DIR__ . '/../includes/I18n.php';

$auth = new Auth();
$auth->requireAdmin();
I18n::init();

$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        $db->setSetting('enforce_2fa_admins',   isset($_POST['enforce_2fa_admins']) ? '1' : '0');
        $db->setSetting('user_daily_voucher_limit', max(0, (int)($_POST['user_daily_voucher_limit'] ?? 0)));
        $db->setSetting('trusted_proxy',        trim($_POST['trusted_proxy'] ?? ''));
        $db->setSetting('webhook_enabled',      isset($_POST['webhook_enabled']) ? '1' : '0');
        $db->setSetting('webhook_url',          trim($_POST['webhook_url'] ?? ''));
        $db->setSetting('cleanup_expired_days', max(0, (int)($_POST['cleanup_expired_days'] ?? 0)));
        $db->setSetting('cleanup_audit_days',   max(0, (int)($_POST['cleanup_audit_days'] ?? 0)));
        $db->setSetting('cleanup_login_days',   max(0, (int)($_POST['cleanup_login_days'] ?? 30)));
        $auth->writeAuditLog($_SESSION['user_id'], 'settings_update', 'config', null, 'Integration/Wartung gespeichert');
        $success = 'Einstellungen gespeichert.';
    }
}

if (isset($_GET['test_webhook']) && isset($_GET['token']) && $auth->validateCsrfToken($_GET['token'])) {
    Notifier::send('✅ Test-Benachrichtigung vom UniFi Voucher System.', ['type' => 'test']);
    $success = 'Test-Benachrichtigung gesendet (sofern Webhook aktiv & URL gültig).';
}

$enforce2fa        = $db->getSetting('enforce_2fa_admins', '0') === '1';
$dailyLimit        = (int)$db->getSetting('user_daily_voucher_limit', 0);
$trustedProxy      = $db->getSetting('trusted_proxy', '');
$webhookEnabled    = $db->getSetting('webhook_enabled', '0') === '1';
$webhookUrl        = $db->getSetting('webhook_url', '');
$cleanupExpired    = (int)$db->getSetting('cleanup_expired_days', 0);
$cleanupAudit      = (int)$db->getSetting('cleanup_audit_days', 0);
$cleanupLogin      = (int)$db->getSetting('cleanup_login_days', 30);
$lastCleanup       = $db->getSetting('last_cleanup', '');
$csrf = $auth->getCsrfToken();
$currentPage = 'integrations';
$adminBase   = '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Integration & Wartung – <?= htmlspecialchars($appTitle) ?></title>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
.card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:24px; margin-bottom:22px; box-shadow:0 4px 14px var(--shadow); max-width:680px; }
.card h2 { font-size:16px; margin-bottom:6px; color:var(--text-primary); }
.muted { color:var(--text-muted); font-size:13px; margin-bottom:14px; }
label { display:block; font-size:14px; color:var(--text-secondary); margin:14px 0 6px; }
.input { width:100%; padding:11px; border:2px solid var(--border-color); border-radius:8px; background:var(--bg-input,#fff); color:var(--text-primary); font-size:14px; }
.row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
.chk { display:flex; align-items:center; gap:9px; margin-top:14px; color:var(--text-primary); font-size:14px; }
.btn { padding:11px 18px; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:14px; text-decoration:none; display:inline-block; }
.btn-primary { background:var(--accent,#667eea); color:#fff; } .btn-secondary { background:var(--bg-hover); color:var(--text-primary); border:1px solid var(--border-color); }
.alert { padding:12px 14px; border-radius:9px; font-size:14px; margin-bottom:18px; max-width:680px; }
.alert-error { background:#fee; border:1px solid #fcc; color:#c33; } .alert-ok { background:#efe; border:1px solid #cfc; color:#2a7; }
</style>
</head>
<body>
<h1 style="font-size:24px;margin-bottom:20px;color:var(--text-primary);">🔧 Integration &amp; Wartung</h1>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

<div class="card">
    <h2>Sicherheitsrichtlinie</h2>
    <p class="muted">Erzwingt Zwei-Faktor-Authentifizierung für alle Administrator-Konten (lokale Accounts). Admins ohne 2FA werden bei der nächsten Aktion zur Einrichtung geleitet.</p>
    <label class="chk"><input type="checkbox" name="enforce_2fa_admins" <?= $enforce2fa ? 'checked' : '' ?>> 2FA für Administratoren verpflichtend</label>
    <label>Tageslimit Voucher pro Nicht-Admin-Benutzer (0 = unbegrenzt)</label>
    <input class="input" type="number" min="0" name="user_daily_voucher_limit" value="<?= $dailyLimit ?>" style="max-width:200px;">
</div>

<div class="card">
    <h2>Reverse-Proxy</h2>
    <p class="muted">IP-Adressen vertrauenswürdiger Proxies (kommasepariert). Nur dann wird die echte Client-IP aus <code>X-Forwarded-For</code> für Rate-Limit & Audit verwendet.</p>
    <input class="input" type="text" name="trusted_proxy" value="<?= htmlspecialchars($trustedProxy) ?>" placeholder="z.B. 10.0.0.1, 172.18.0.1">
</div>

<div class="card">
    <h2>Webhook-Benachrichtigungen</h2>
    <p class="muted">Slack-, Microsoft-Teams- oder generische JSON-Webhook-URL. Wird bei Voucher-Erstellung ausgelöst.</p>
    <label class="chk"><input type="checkbox" name="webhook_enabled" <?= $webhookEnabled ? 'checked' : '' ?>> Webhook aktiv</label>
    <label>Webhook-URL</label>
    <input class="input" type="url" name="webhook_url" value="<?= htmlspecialchars($webhookUrl) ?>" placeholder="https://hooks.slack.com/services/…">
    <div style="margin-top:12px;">
        <a class="btn btn-secondary" href="?test_webhook=1&token=<?= urlencode($csrf) ?>">Test senden</a>
    </div>
</div>

<div class="card">
    <h2>Datenhaltung & Cleanup (DSGVO)</h2>
    <p class="muted">Aufbewahrungsfristen in Tagen (0 = deaktiviert). Ausführung per <code>cron_cleanup.php</code> (täglich empfohlen).
        <?php if ($lastCleanup): ?><br>Letzter Lauf: <?= htmlspecialchars($lastCleanup) ?><?php endif; ?>
    </p>
    <div class="row">
        <div><label>Abgelaufene Voucher</label><input class="input" type="number" min="0" name="cleanup_expired_days" value="<?= $cleanupExpired ?>"></div>
        <div><label>Audit-Log</label><input class="input" type="number" min="0" name="cleanup_audit_days" value="<?= $cleanupAudit ?>"></div>
        <div><label>Login-Versuche</label><input class="input" type="number" min="0" name="cleanup_login_days" value="<?= $cleanupLogin ?>"></div>
    </div>
</div>

<button class="btn btn-primary" type="submit" name="save">Speichern</button>
</form>

</div><!-- /main-content -->
<script src="../assets/global.js"></script>
</body>
</html>
