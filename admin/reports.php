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

// Zeitraum (Tage)
$days = max(1, min(365, (int)($_GET['days'] ?? 30)));

// CSV-Export
if (isset($_GET['export'])) {
    $auth->requireAdmin();
    header('Content-Type: text/csv; charset=utf-8');
    $fn = 'report-' . $_GET['export'] . '-' . date('Y-m-d') . '.csv';
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel

    if ($_GET['export'] === 'per_site') {
        fputcsv($out, ['Site', 'Gesamt', 'Gültig', 'Verwendet', 'Abgelaufen']);
        $rows = $db->fetchAll(
            "SELECT s.name,
                COUNT(v.id) total,
                SUM(v.status='valid') valid,
                SUM(v.status='used') used,
                SUM(v.status='expired') expired
             FROM sites s LEFT JOIN vouchers v ON v.site_id=s.id
             GROUP BY s.id ORDER BY total DESC"
        );
        foreach ($rows as $r) fputcsv($out, [$r['name'], (int)$r['total'], (int)$r['valid'], (int)$r['used'], (int)$r['expired']]);
    } elseif ($_GET['export'] === 'per_user') {
        fputcsv($out, ['Benutzer', 'E-Mail', 'Voucher erstellt']);
        $rows = $db->fetchAll(
            "SELECT u.name, u.email, COUNT(v.id) c FROM users u
             LEFT JOIN vouchers v ON v.user_id=u.id GROUP BY u.id ORDER BY c DESC"
        );
        foreach ($rows as $r) fputcsv($out, [$r['name'], $r['email'], (int)$r['c']]);
    } else { // daily
        fputcsv($out, ['Datum', 'Erstellte Voucher']);
        $rows = $db->fetchAll(
            "SELECT DATE(created_at) d, COUNT(*) c FROM vouchers
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at) ORDER BY d", [$days]
        );
        foreach ($rows as $r) fputcsv($out, [$r['d'], (int)$r['c']]);
    }
    fclose($out);
    exit;
}

// Kennzahlen
$totals = $db->fetchOne(
    "SELECT COUNT(*) total,
        SUM(status='valid') valid, SUM(status='used') used, SUM(status='expired') expired
     FROM vouchers"
);
$inPeriod = (int)($db->fetchOne(
    "SELECT COUNT(*) c FROM vouchers WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)", [$days]
)['c'] ?? 0);

$perSite = $db->fetchAll(
    "SELECT s.name,
        COUNT(v.id) total, SUM(v.status='valid') valid, SUM(v.status='used') used, SUM(v.status='expired') expired
     FROM sites s LEFT JOIN vouchers v ON v.site_id=s.id GROUP BY s.id ORDER BY total DESC"
);
$perUser = $db->fetchAll(
    "SELECT u.name, COUNT(v.id) c FROM users u LEFT JOIN vouchers v ON v.user_id=u.id
     GROUP BY u.id HAVING c > 0 ORDER BY c DESC LIMIT 10"
);
$daily = $db->fetchAll(
    "SELECT DATE(created_at) d, COUNT(*) c FROM vouchers
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(created_at) ORDER BY d", [$days]
);
$chartLabels = array_map(fn($r) => date('d.m', strtotime($r['d'])), $daily);
$chartData   = array_map(fn($r) => (int)$r['c'], $daily);

$csrf = $auth->getCsrfToken();
$currentPage = 'reports';
$adminBase   = '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reporting – <?= htmlspecialchars($appTitle) ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php require __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
.card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:22px; margin-bottom:20px; box-shadow:0 4px 14px var(--shadow); }
.card h2 { font-size:15px; margin-bottom:14px; color:var(--text-primary); }
.grid4 { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
.stat { background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:20px; }
.stat .n { font-size:28px; font-weight:700; color:var(--text-primary); } .stat .l { color:var(--text-muted); font-size:12.5px; margin-top:3px; }
table { width:100%; border-collapse:collapse; } th,td { text-align:left; padding:10px 8px; font-size:14px; border-bottom:1px solid var(--border-color); color:var(--text-primary); } th { color:var(--text-muted); }
.btn { padding:9px 15px; border:none; border-radius:8px; font-weight:600; font-size:13px; text-decoration:none; display:inline-block; cursor:pointer; }
.btn-s { background:var(--bg-hover); color:var(--text-primary); border:1px solid var(--border-color); }
.toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
select.input { padding:9px; border:2px solid var(--border-color); border-radius:8px; background:var(--bg-input,#fff); color:var(--text-primary); }
@media print { .sidebar,.header,.toolbar,.no-print { display:none !important; } .main-content { margin:0 !important; } }
</style>
</head>
<body>
<h1 style="font-size:24px;margin-bottom:18px;color:var(--text-primary);">📊 Reporting</h1>

<div class="toolbar no-print">
    <form method="get" style="display:flex;gap:8px;align-items:center;">
        <label style="color:var(--text-muted);font-size:13px;">Zeitraum:</label>
        <select class="input" name="days" onchange="this.form.submit()">
            <?php foreach ([7,30,90,365] as $d): ?>
            <option value="<?= $d ?>" <?= $days===$d?'selected':'' ?>><?= $d ?> Tage</option>
            <?php endforeach; ?>
        </select>
    </form>
    <a class="btn btn-s" href="?export=daily&days=<?= $days ?>">⬇️ CSV (täglich)</a>
    <a class="btn btn-s" href="?export=per_site">⬇️ CSV (pro Site)</a>
    <a class="btn btn-s" href="?export=per_user">⬇️ CSV (pro Nutzer)</a>
    <button class="btn btn-s" onclick="window.print()">🖨️ Drucken/PDF</button>
</div>

<div class="grid4">
    <div class="stat"><div class="n"><?= (int)$totals['total'] ?></div><div class="l">Vouchers gesamt</div></div>
    <div class="stat"><div class="n"><?= (int)$totals['valid'] ?></div><div class="l">Gültig</div></div>
    <div class="stat"><div class="n"><?= (int)$totals['used'] ?></div><div class="l">Verwendet</div></div>
    <div class="stat"><div class="n"><?= $inPeriod ?></div><div class="l">In <?= $days ?> Tagen erstellt</div></div>
</div>

<div class="card">
    <h2>Erstellte Voucher (<?= $days ?> Tage)</h2>
    <canvas id="chart" height="90"></canvas>
</div>

<div class="card">
    <h2>Pro Site</h2>
    <table><tr><th>Site</th><th>Gesamt</th><th>Gültig</th><th>Verwendet</th><th>Abgelaufen</th></tr>
    <?php foreach ($perSite as $r): ?>
        <tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= (int)$r['total'] ?></td><td><?= (int)$r['valid'] ?></td><td><?= (int)$r['used'] ?></td><td><?= (int)$r['expired'] ?></td></tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Top-Nutzer</h2>
    <table><tr><th>Benutzer</th><th>Voucher erstellt</th></tr>
    <?php foreach ($perUser as $r): ?>
        <tr><td><?= htmlspecialchars($r['name'] ?? '–') ?></td><td><?= (int)$r['c'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($perUser)): ?><tr><td colspan="2" style="color:var(--text-muted);">Keine Daten</td></tr><?php endif; ?>
    </table>
</div>

</div><!-- /main-content -->
<script src="../assets/global.js"></script>
<script>
new Chart(document.getElementById('chart'), {
  type:'line',
  data:{ labels: <?= json_encode($chartLabels) ?>, datasets:[{ label:'Voucher', data: <?= json_encode($chartData) ?>, borderColor:'#667eea', backgroundColor:'rgba(102,126,234,.15)', fill:true, tension:.3 }] },
  options:{ plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{precision:0}}} }
});
</script>
</body>
</html>
