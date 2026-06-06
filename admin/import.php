<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/UniFiController.php';
require_once __DIR__ . '/../includes/Notifier.php';
require_once __DIR__ . '/../includes/I18n.php';

$auth = new Auth();
$auth->requireAdmin();
I18n::init();

$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
$defaultExpire  = max(1, (int)$db->getSetting('default_expire_minutes', 480));
$defaultMaxUses = max(1, (int)$db->getSetting('default_max_uses', 1));

$error = '';
$success = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        try {
            $siteId = (int)($_POST['site_id'] ?? 0);
            $site = $db->fetchOne("SELECT * FROM sites WHERE id=? AND is_active=1", [$siteId]);
            if (!$site) throw new Exception('Site nicht gefunden');

            // CSV-Quelle: Datei bevorzugt, sonst Textarea
            $raw = '';
            if (!empty($_FILES['csv']['tmp_name'])) {
                $raw = file_get_contents($_FILES['csv']['tmp_name']);
            } else {
                $raw = (string)($_POST['csv_text'] ?? '');
            }
            $lines = preg_split('/\r\n|\r|\n/', trim($raw));
            if (count($lines) > 200) throw new Exception('Maximal 200 Zeilen pro Import.');

            $controller = new UniFiController(
                $site['unifi_controller_url'], $site['unifi_username'],
                Crypto::decrypt($site['unifi_password']), $site['site_id']
            );

            $created = 0;
            foreach ($lines as $i => $line) {
                $line = trim($line);
                if ($line === '') continue;
                $cols = str_getcsv($line);
                $name = trim((string)($cols[0] ?? ''));
                if ($name === '' || strtolower($name) === 'name') continue; // Header/leer überspringen
                $maxUses = isset($cols[1]) && $cols[1] !== '' ? max(1, (int)$cols[1]) : $defaultMaxUses;
                $expire  = isset($cols[2]) && $cols[2] !== '' ? max(1, (int)$cols[2]) : $defaultExpire;
                try {
                    $v = $controller->createVoucher(date('Y-m-d') . '_' . $name, $maxUses, $expire);
                    if (!is_array($v) || empty($v['formatted_code'])) throw new Exception('ungültige Antwort');
                    $db->execute(
                        "INSERT INTO vouchers (site_id, user_id, voucher_code, voucher_name, max_uses, expire_minutes, unifi_voucher_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [$siteId, $_SESSION['user_id'], $v['code'], date('Y-m-d') . '_' . $name, $maxUses, $expire, $v['unifi_id'] ?? null]
                    );
                    $results[] = ['name' => $name, 'code' => $v['formatted_code'], 'ok' => true];
                    $created++;
                } catch (Exception $e) {
                    $results[] = ['name' => $name, 'code' => $e->getMessage(), 'ok' => false];
                }
            }
            if ($created > 0) {
                Notifier::voucherCreated($created, $site['name'], $_SESSION['user_name'] ?? null);
                $auth->writeAuditLog($_SESSION['user_id'], 'voucher_import', 'site', $siteId, "$created Voucher importiert");
            }
            $success = "$created Voucher erstellt.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$sites = $db->fetchAll("SELECT * FROM sites WHERE is_active=1 ORDER BY name");
$csrf = $auth->getCsrfToken();
$currentPage = 'import';
$adminBase   = '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CSV-Import – <?= htmlspecialchars($appTitle) ?></title>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
.card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:24px; margin-bottom:20px; box-shadow:0 4px 14px var(--shadow); max-width:760px; }
.card h2 { font-size:15px; margin-bottom:12px; color:var(--text-primary); }
.muted { color:var(--text-muted); font-size:13px; margin-bottom:12px; }
label { display:block; font-size:13px; color:var(--text-secondary); margin:12px 0 6px; }
.input,textarea,select { width:100%; padding:11px; border:2px solid var(--border-color); border-radius:8px; background:var(--bg-input,#fff); color:var(--text-primary); font-size:14px; font-family:inherit; }
textarea { min-height:140px; font-family:monospace; }
.btn { padding:11px 18px; border:none; border-radius:8px; font-weight:600; font-size:14px; cursor:pointer; background:var(--accent,#667eea); color:#fff; }
.alert { padding:12px 14px; border-radius:9px; font-size:14px; margin-bottom:18px; max-width:760px; }
.alert-error { background:#fee; border:1px solid #fcc; color:#c33; } .alert-ok { background:#efe; border:1px solid #cfc; color:#2a7; }
table { width:100%; border-collapse:collapse; } th,td { text-align:left; padding:8px; font-size:13px; border-bottom:1px solid var(--border-color); color:var(--text-primary); }
code { font-family:monospace; background:var(--bg-hover); padding:2px 6px; border-radius:5px; }
</style>
</head>
<body>
<h1 style="font-size:24px;margin-bottom:18px;color:var(--text-primary);">📥 Voucher-Import (CSV)</h1>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="card">
    <h2>Mehrere Voucher erstellen</h2>
    <p class="muted">Eine Zeile pro Voucher: <code>Name,MaxGeräte,Minuten</code> – MaxGeräte und Minuten sind optional (Standardwerte greifen). Max. 200 Zeilen. Beispiel:<br>
        <code>Gast Müller,1,480</code> · <code>Konferenzraum A,5,240</code> · <code>Tagespass</code></p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <label>Standort</label>
        <select class="input" name="site_id" required>
            <?php foreach ($sites as $s): ?><option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
        </select>
        <label>CSV-Datei (optional)</label>
        <input class="input" type="file" name="csv" accept=".csv,text/csv">
        <label>… oder direkt einfügen</label>
        <textarea name="csv_text" placeholder="Gast Müller,1,480&#10;Konferenzraum A,5,240"></textarea>
        <button class="btn" type="submit" name="do_import" style="margin-top:14px;" onclick="return confirm('Import jetzt starten?');">Importieren</button>
    </form>
</div>

<?php if (!empty($results)): ?>
<div class="card">
    <h2>Ergebnis</h2>
    <table><tr><th>Name</th><th>Code / Fehler</th><th>Status</th></tr>
    <?php foreach ($results as $r): ?>
        <tr><td><?= htmlspecialchars($r['name']) ?></td><td><code><?= htmlspecialchars($r['code']) ?></code></td><td><?= $r['ok'] ? '✅' : '❌' ?></td></tr>
    <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

</div><!-- /main-content -->
<script src="../assets/global.js"></script>
</body>
</html>
