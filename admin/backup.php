<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/I18n.php';

$auth = new Auth();
$auth->requireAdmin();
I18n::init();

$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

$error = '';
$success = '';

// Export: JSON-Download
if (isset($_GET['export']) && isset($_GET['token']) && $auth->validateCsrfToken($_GET['token'])) {
    $export = [
        'meta' => [
            'app'        => 'unifi-voucher-tool',
            'version'    => '2.2.0',
            'exported_at'=> date('c'),
            'note'       => 'Site-Passwörter sind mit dem APP_KEY dieser Installation verschlüsselt.',
        ],
        'settings'          => $db->fetchAll("SELECT setting_key, setting_value FROM settings"),
        'sites'             => $db->fetchAll("SELECT name, site_id, unifi_controller_url, unifi_username, unifi_password, is_active, public_access FROM sites"),
        'voucher_templates' => $db->fetchAll("SELECT name, max_uses, expire_minutes, description, qos_rate_max_down, qos_rate_max_up, qos_usage_quota, is_active FROM voucher_templates"),
    ];
    $auth->writeAuditLog($_SESSION['user_id'], 'config_export', 'config', null, 'Konfiguration exportiert');
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="voucher-config-' . date('Y-m-d') . '.json"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } elseif (empty($_FILES['backup']['tmp_name'])) {
        $error = 'Bitte eine Backup-Datei auswählen.';
    } else {
        $raw  = file_get_contents($_FILES['backup']['tmp_name']);
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['meta']['app'] ?? '') !== 'unifi-voucher-tool') {
            $error = 'Ungültige oder fremde Backup-Datei.';
        } else {
            $importSites     = isset($_POST['import_sites']);
            $importTemplates = isset($_POST['import_templates']);
            $importSettings  = isset($_POST['import_settings']);
            $counts = ['settings' => 0, 'sites' => 0, 'templates' => 0];

            try {
                if ($importSettings && !empty($data['settings'])) {
                    foreach ($data['settings'] as $s) {
                        // Cron-Token NICHT überschreiben (Sicherheit der Ziel-Installation)
                        if (($s['setting_key'] ?? '') === 'cron_token') continue;
                        $db->setSetting($s['setting_key'], $s['setting_value']);
                        $counts['settings']++;
                    }
                }
                if ($importSites && !empty($data['sites'])) {
                    foreach ($data['sites'] as $s) {
                        $exists = $db->fetchOne("SELECT id FROM sites WHERE name = ? AND site_id = ?", [$s['name'], $s['site_id']]);
                        if ($exists) {
                            $db->query(
                                "UPDATE sites SET unifi_controller_url=?, unifi_username=?, unifi_password=?, is_active=?, public_access=? WHERE id=?",
                                [$s['unifi_controller_url'], $s['unifi_username'], $s['unifi_password'], (int)$s['is_active'], (int)$s['public_access'], $exists['id']]
                            );
                        } else {
                            $db->query(
                                "INSERT INTO sites (name, site_id, unifi_controller_url, unifi_username, unifi_password, is_active, public_access) VALUES (?,?,?,?,?,?,?)",
                                [$s['name'], $s['site_id'], $s['unifi_controller_url'], $s['unifi_username'], $s['unifi_password'], (int)$s['is_active'], (int)$s['public_access']]
                            );
                        }
                        $counts['sites']++;
                    }
                }
                if ($importTemplates && !empty($data['voucher_templates'])) {
                    foreach ($data['voucher_templates'] as $t) {
                        $exists = $db->fetchOne("SELECT id FROM voucher_templates WHERE name = ?", [$t['name']]);
                        if (!$exists) {
                            $db->query(
                                "INSERT INTO voucher_templates (name, max_uses, expire_minutes, description, qos_rate_max_down, qos_rate_max_up, qos_usage_quota, is_active) VALUES (?,?,?,?,?,?,?,?)",
                                [$t['name'], (int)$t['max_uses'], (int)$t['expire_minutes'], $t['description'] ?? null,
                                 $t['qos_rate_max_down'] ?? null, $t['qos_rate_max_up'] ?? null, $t['qos_usage_quota'] ?? null, (int)($t['is_active'] ?? 1)]
                            );
                            $counts['templates']++;
                        }
                    }
                }
                $auth->writeAuditLog($_SESSION['user_id'], 'config_import', 'config', null, 'Konfiguration importiert');
                $success = "Import abgeschlossen: {$counts['settings']} Einstellungen, {$counts['sites']} Sites, {$counts['templates']} Profile.";
            } catch (Exception $e) {
                $error = 'Import-Fehler: ' . $e->getMessage();
            }
        }
    }
}

$csrf = $auth->getCsrfToken();
$currentPage = 'backup';
$adminBase   = '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Backup & Restore – <?= htmlspecialchars($appTitle) ?></title>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
.card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:24px; margin-bottom:22px; box-shadow:0 4px 14px var(--shadow); max-width:680px; }
.card h2 { font-size:16px; margin-bottom:12px; color:var(--text-primary); }
.muted { color:var(--text-muted); font-size:13px; margin-bottom:14px; }
.btn { padding:11px 18px; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:14px; text-decoration:none; display:inline-block; }
.btn-primary { background:var(--accent,#667eea); color:#fff; }
.btn-secondary { background:var(--bg-hover); color:var(--text-primary); border:1px solid var(--border-color); }
.alert { padding:12px 14px; border-radius:9px; font-size:14px; margin-bottom:18px; max-width:680px; }
.alert-error { background:#fee; border:1px solid #fcc; color:#c33; }
.alert-ok { background:#efe; border:1px solid #cfc; color:#2a7; }
label.chk { display:flex; align-items:center; gap:9px; margin:8px 0; color:var(--text-primary); font-size:14px; }
input[type=file] { margin:10px 0; color:var(--text-primary); }
</style>
</head>
<body>
<h1 style="font-size:24px;margin-bottom:20px;color:var(--text-primary);">💾 Backup &amp; Restore</h1>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="card">
    <h2>Export</h2>
    <p class="muted">Lädt Einstellungen, Sites und Voucher-Profile als JSON. Site-Passwörter bleiben mit dem <code>APP_KEY</code> dieser Installation verschlüsselt – ein Restore auf einer Installation mit anderem APP_KEY kann sie nicht entschlüsseln.</p>
    <a class="btn btn-primary" href="?export=1&token=<?= urlencode($csrf) ?>">Konfiguration exportieren</a>
</div>

<div class="card">
    <h2>Import / Restore</h2>
    <p class="muted">Vorhandene Sites werden anhand von Name + Site-ID aktualisiert, neue hinzugefügt. Profile werden nur angelegt, wenn der Name noch nicht existiert. Der Cron-Token wird nie überschrieben.</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="file" name="backup" accept="application/json,.json" required><br>
        <label class="chk"><input type="checkbox" name="import_settings" checked> Einstellungen</label>
        <label class="chk"><input type="checkbox" name="import_sites" checked> Sites</label>
        <label class="chk"><input type="checkbox" name="import_templates" checked> Voucher-Profile</label>
        <button class="btn btn-primary" type="submit" name="import" style="margin-top:12px;" onclick="return confirm('Import jetzt durchführen?');">Importieren</button>
    </form>
</div>

</div><!-- /main-content -->
<script src="../assets/global.js"></script>
</body>
</html>
