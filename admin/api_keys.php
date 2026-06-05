<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/ApiKey.php';
require_once __DIR__ . '/../includes/I18n.php';

$auth = new Auth();
$auth->requireAdmin();
I18n::init();

$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

$error = '';
$success = '';
$newKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_key'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = __('error_name_req');
        } else {
            $k = ApiKey::generate();
            $db->execute(
                "INSERT INTO api_keys (name, key_prefix, key_hash, created_by) VALUES (?, ?, ?, ?)",
                [$name, $k['prefix'], $k['hash'], $_SESSION['user_id']]
            );
            $auth->writeAuditLog($_SESSION['user_id'], 'api_key_create', 'api_key', null, "API-Key '$name' erstellt");
            $newKey = $k['plain'];
            $success = 'API-Schlüssel erstellt. Bitte JETZT kopieren – er wird nur einmal angezeigt!';
        }
    }
}

if (isset($_GET['toggle']) && isset($_GET['token']) && $auth->validateCsrfToken($_GET['token'])) {
    $row = $db->fetchOne("SELECT is_active FROM api_keys WHERE id = ?", [(int)$_GET['toggle']]);
    if ($row) {
        $db->query("UPDATE api_keys SET is_active = ? WHERE id = ?", [$row['is_active'] ? 0 : 1, (int)$_GET['toggle']]);
        $success = 'Status aktualisiert.';
    }
}

if (isset($_GET['delete']) && isset($_GET['token']) && $auth->validateCsrfToken($_GET['token'])) {
    $db->query("DELETE FROM api_keys WHERE id = ?", [(int)$_GET['delete']]);
    $auth->writeAuditLog($_SESSION['user_id'], 'api_key_delete', 'api_key', (int)$_GET['delete'], 'API-Key gelöscht');
    $success = 'API-Schlüssel gelöscht.';
}

$keys = $db->fetchAll("SELECT k.*, u.name AS creator FROM api_keys k LEFT JOIN users u ON k.created_by = u.id ORDER BY k.created_at DESC");
$csrf = $auth->getCsrfToken();
$currentPage = 'api_keys';
$adminBase   = '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API-Schlüssel – <?= htmlspecialchars($appTitle) ?></title>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
.card { background: var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:24px; margin-bottom:22px; box-shadow:0 4px 14px var(--shadow); }
.card h2 { font-size:16px; margin-bottom:16px; color:var(--text-primary); }
table { width:100%; border-collapse:collapse; }
th,td { text-align:left; padding:11px 8px; font-size:14px; border-bottom:1px solid var(--border-color); color:var(--text-primary); }
th { color:var(--text-muted); font-weight:600; }
code { font-family:monospace; background:var(--bg-hover); padding:2px 6px; border-radius:5px; }
.btn { padding:10px 16px; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:14px; text-decoration:none; display:inline-block; }
.btn-primary { background:var(--accent,#667eea); color:#fff; }
.input { width:100%; padding:11px; border:2px solid var(--border-color); border-radius:8px; background:var(--bg-input,#fff); color:var(--text-primary); font-size:14px; }
.alert { padding:12px 14px; border-radius:9px; font-size:14px; margin-bottom:18px; }
.alert-error { background:#fee; border:1px solid #fcc; color:#c33; }
.alert-ok { background:#efe; border:1px solid #cfc; color:#2a7; }
.keybox { background:#0f1117; color:#7CFFB2; font-family:monospace; padding:14px; border-radius:8px; word-break:break-all; font-size:15px; margin-top:10px; }
.badge { padding:3px 9px; border-radius:6px; font-size:12px; font-weight:600; }
.b-on { background:#e3f6ea; color:#2a7; } .b-off { background:#fdeaea; color:#c33; }
.a-link { color:var(--accent,#667eea); text-decoration:none; margin-right:10px; font-size:13px; }
.muted { color:var(--text-muted); font-size:13px; }
</style>
</head>
<body>
<h1 style="font-size:24px;margin-bottom:20px;color:var(--text-primary);">🔑 API-Schlüssel</h1>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<?php if ($newKey): ?>
<div class="card">
    <h2>Neuer Schlüssel</h2>
    <p class="muted">Kopieren Sie ihn jetzt – aus Sicherheitsgründen wird er nicht erneut angezeigt.</p>
    <div class="keybox"><?= htmlspecialchars($newKey) ?></div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Neuen API-Schlüssel erstellen</h2>
    <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div style="flex:1;min-width:220px;">
            <label class="muted" style="display:block;margin-bottom:6px;">Bezeichnung</label>
            <input class="input" type="text" name="name" placeholder="z.B. Buchungssystem, Terminal Foyer" required>
        </div>
        <button class="btn btn-primary" type="submit" name="create_key">Erstellen</button>
    </form>
</div>

<div class="card">
    <h2>Vorhandene Schlüssel</h2>
    <?php if (empty($keys)): ?>
        <p class="muted">Noch keine API-Schlüssel angelegt.</p>
    <?php else: ?>
    <table>
        <tr><th>Name</th><th>Präfix</th><th>Status</th><th>Zuletzt genutzt</th><th>Erstellt von</th><th></th></tr>
        <?php foreach ($keys as $k): ?>
        <tr>
            <td><?= htmlspecialchars($k['name']) ?></td>
            <td><code>uvt_<?= htmlspecialchars($k['key_prefix']) ?>…</code></td>
            <td><span class="badge <?= $k['is_active'] ? 'b-on' : 'b-off' ?>"><?= $k['is_active'] ? 'aktiv' : 'gesperrt' ?></span></td>
            <td class="muted"><?= $k['last_used_at'] ? htmlspecialchars($k['last_used_at']) : '–' ?></td>
            <td class="muted"><?= htmlspecialchars($k['creator'] ?? '–') ?></td>
            <td style="text-align:right;white-space:nowrap;">
                <a class="a-link" href="?toggle=<?= (int)$k['id'] ?>&token=<?= urlencode($csrf) ?>"><?= $k['is_active'] ? 'Sperren' : 'Aktivieren' ?></a>
                <a class="a-link" style="color:#e25555;" href="?delete=<?= (int)$k['id'] ?>&token=<?= urlencode($csrf) ?>" onclick="return confirm('Schlüssel löschen?');">Löschen</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Verwendung</h2>
    <p class="muted" style="margin-bottom:10px;">Authentifizierung per Header <code>Authorization: Bearer &lt;key&gt;</code> oder <code>X-API-Key: &lt;key&gt;</code>.</p>
    <pre class="keybox" style="color:#cdd3e0;white-space:pre-wrap;"># Voucher erstellen
curl -X POST https://IHRE-DOMAIN/api/vouchers.php \
  -H "Authorization: Bearer uvt_…" \
  -H "Content-Type: application/json" \
  -d '{"site_id":1,"name":"API Gast","max_uses":1,"expire_minutes":480}'

# Sites auflisten
curl https://IHRE-DOMAIN/api/sites.php -H "X-API-Key: uvt_…"</pre>
</div>

</div><!-- /main-content -->
<script src="../assets/global.js"></script>
</body>
</html>
