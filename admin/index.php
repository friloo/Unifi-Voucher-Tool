<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/UniFiController.php';
require_once __DIR__ . '/../includes/I18n.php';

$auth = new Auth();
$auth->requireAdmin();
$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
I18n::init();

// AJAX: Statistiken
if (isset($_GET['ajax_stats'])) {
    header('Content-Type: application/json');
    $syncFirst = isset($_GET['sync']) && $_GET['sync'] == '1';
    try {
        $sites      = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1");
        $siteData   = [];
        $totalStats = ['total' => 0, 'valid' => 0, 'used' => 0, 'expired' => 0];
        $syncErrors = [];

        if ($syncFirst) {
            // Mehrere Sites werden sequentiell synchronisiert (je bis zu ~15s
            // bei Timeout) – PHP-Default von 30s reicht dann nicht.
            @set_time_limit(30 + count($sites) * 20);
            foreach ($sites as $site) {
                try {
                    $ctrl = new UniFiController($site['unifi_controller_url'], $site['unifi_username'], Crypto::decrypt($site['unifi_password']), $site['site_id']);
                    $ctrl->syncVouchersToDatabase($db, $site['id']);
                } catch (Exception $e) {
                    $syncErrors[$site['id']] = $e->getMessage();
                }
            }
            $db->execute("INSERT INTO settings (setting_key,setting_value) VALUES ('last_cron_sync',NOW()) ON DUPLICATE KEY UPDATE setting_value=NOW()");
        }

        foreach ($sites as $site) {
            $ss = $db->fetchOne("SELECT COUNT(*) as total, SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END) as valid, SUM(CASE WHEN status='used' THEN 1 ELSE 0 END) as used, SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) as expired FROM vouchers WHERE site_id=?", [$site['id']]);
            $siteData[] = ['site_id' => $site['id'], 'site_name' => $site['name'], 'stats' => ['total' => (int)($ss['total']??0), 'valid' => (int)($ss['valid']??0), 'used' => (int)($ss['used']??0), 'expired' => (int)($ss['expired']??0)], 'error' => $syncErrors[$site['id']] ?? null];
            $totalStats['total']   += (int)($ss['total']??0);
            $totalStats['valid']   += (int)($ss['valid']??0);
            $totalStats['used']    += (int)($ss['used']??0);
            $totalStats['expired'] += (int)($ss['expired']??0);
        }

        $lastSync = $db->getSetting('last_cron_sync', '');
        echo json_encode(['success' => true, 'synced' => $syncFirst, 'sites' => $siteData, 'total' => $totalStats, 'last_sync' => $lastSync ? date('d.m.Y H:i:s', strtotime($lastSync)) : null, 'timestamp' => date('H:i:s')]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$stats = [
    'total_sites'          => $db->fetchOne("SELECT COUNT(*) as count FROM sites WHERE is_active=1")['count'],
    'total_users'          => $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE is_active=1")['count'],
    'total_vouchers_today' => $db->fetchOne("SELECT COUNT(*) as count FROM vouchers WHERE DATE(created_at)=CURDATE()")['count'],
];

$sites = $db->fetchAll("SELECT * FROM sites WHERE is_active=1");

$voucherStats = $db->fetchOne("SELECT COUNT(*) as total, SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END) as valid, SUM(CASE WHEN status='used' THEN 1 ELSE 0 END) as used, SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) as expired FROM vouchers");

$siteStats = [];
foreach ($sites as $site) {
    $siteStats[$site['id']] = $db->fetchOne("SELECT COUNT(*) as total, SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END) as valid, SUM(CASE WHEN status='used' THEN 1 ELSE 0 END) as used, SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) as expired FROM vouchers WHERE site_id=?", [$site['id']]);
}

$lastCronSync = $db->getSetting('last_cron_sync', '');

$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $count = $db->fetchOne("SELECT COUNT(*) as count FROM vouchers WHERE DATE(created_at)=?", [$date])['count'];
    $chartData[] = ['date' => date('d.m', strtotime($date)), 'count' => $count];
}

$topUsers = $db->fetchAll("SELECT u.name, u.email, COUNT(v.id) as voucher_count FROM users u LEFT JOIN vouchers v ON u.id=v.user_id WHERE v.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY u.id ORDER BY voucher_count DESC LIMIT 5");

$recentVouchers = $db->fetchAll("SELECT v.*, s.name as site_name, u.name as user_name FROM vouchers v LEFT JOIN sites s ON v.site_id=s.id LEFT JOIN users u ON v.user_id=u.id ORDER BY v.created_at DESC LIMIT 10");

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('dashboard_title') ?> – <?= htmlspecialchars($appTitle) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php include __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
    .page-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .page-title { font-size: 26px; font-weight: 700; color: var(--text-primary); }
    .page-subtitle { color: var(--text-muted); font-size: 14px; margin-top: 4px; }
    .live-badge { display: inline-flex; align-items: center; gap: 8px; background: #d4edda; color: #155724; padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 500; }
    .live-badge .dot { width: 8px; height: 8px; background: #28a745; border-radius: 50%; animation: pulse 2s infinite; }
    .live-badge.loading { background: #fff3cd; color: #856404; }
    .live-badge.loading .dot { background: #ffc107; }
    .live-badge.error { background: #f8d7da; color: #721c24; }
    .live-badge.error .dot { background: var(--danger); animation: none; }
    @keyframes pulse { 0%,100%{opacity:1}50%{opacity:.5} }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: var(--bg-card); padding: 22px; border-radius: 14px; box-shadow: 0 2px 10px var(--shadow); border: 1px solid var(--border-color); }
    .stat-card.live { border-color: var(--success); border-width: 2px; }
    .stat-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .stat-card-title { color: var(--text-muted); font-size: 13px; font-weight: 500; }
    .stat-card-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; }
    .stat-card-value { font-size: 30px; font-weight: 700; color: var(--text-primary); }
    .stat-card-value.valid { color: var(--success); }
    .stat-card-value.used { color: var(--warning); }
    .stat-card-value.expired { color: var(--danger); }
    .stat-card-sub { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
    .card { background: var(--bg-card); border-radius: 14px; box-shadow: 0 2px 10px var(--shadow); border: 1px solid var(--border-color); margin-bottom: 28px; overflow: hidden; }
    .card-header { padding: 18px 22px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .card-title { font-size: 17px; font-weight: 600; color: var(--text-primary); }
    .card-body { padding: 22px; }
    .table { width: 100%; border-collapse: collapse; }
    .table th { text-align: left; padding: 11px 14px; background: var(--bg-table-head); color: var(--text-muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
    .table td { padding: 13px 14px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); font-size: 14px; }
    .table tr:last-child td { border-bottom: none; }
    .btn-primary   { background: var(--accent); color: white; }
    .btn-primary:hover { background: var(--accent-hover); }
    .btn-success   { background: var(--success); color: white; }
    .btn-success:hover { opacity: .9; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }
    .chart-container { position: relative; height: 280px; }
    .site-status { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 14px; }
    .site-status-card { background: var(--bg-card); border: 2px solid var(--border-color); border-radius: 12px; padding: 18px; transition: border-color .2s; }
    .site-status-card:hover { border-color: var(--accent); }
    .site-status-card.error { border-color: var(--danger); }
    .site-status-name { font-weight: 600; color: var(--text-primary); font-size: 15px; }
    .site-voucher-count { background: var(--accent); color: white; padding: 3px 11px; border-radius: 20px; font-size: 13px; font-weight: 600; }
    .site-voucher-count.zero { background: var(--text-muted); }
    .site-status-stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin-top: 12px; }
    .site-stat { text-align: center; padding: 9px; background: var(--bg-hover); border-radius: 8px; }
    .site-stat-value { font-size: 18px; font-weight: 700; }
    .site-stat-value.valid { color: var(--success); }
    .site-stat-value.used  { color: var(--warning); }
    .site-stat-value.expired { color: var(--danger); }
    .site-stat-label { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
    .site-error { color: var(--danger); font-size: 12px; margin-top: 8px; }
    .top-users-list { list-style: none; }
    .top-users-list li { display: flex; justify-content: space-between; align-items: center; padding: 11px 0; border-bottom: 1px solid var(--border-color); }
    .top-users-list li:last-child { border-bottom: none; }
    .user-info-row { display: flex; align-items: center; gap: 10px; }
    .user-avatar-sm { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg,#667eea,#764ba2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 13px; }
    .user-count { background: var(--bg-hover); padding: 3px 10px; border-radius: 12px; font-weight: 600; color: var(--text-primary); font-size: 13px; }
    .refresh-indicator { display: flex; align-items: center; gap: 10px; }
    .last-update { color: var(--text-muted); font-size: 12px; }
    .empty-state { text-align: center; padding: 50px 20px; color: var(--text-muted); }
    .empty-state i { font-size: 40px; margin-bottom: 15px; opacity: .3; display: block; }
    @media(max-width:768px){ .main-content{ margin-left:0!important; } .stats-grid{ grid-template-columns:1fr 1fr; } }
</style>

<?php if (!Crypto::hasKey()): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-triangle"></i>
    <span><?= __('crypto_warning') ?></span>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= __('dashboard_title') ?></h1>
        <p class="page-subtitle"><?= __('dashboard_subtitle') ?></p>
    </div>
    <div class="refresh-indicator">
        <span id="liveBadge" class="live-badge">
            <span class="dot"></span>
            <span id="liveStatus">DB</span>
        </span>
        <button onclick="refreshData('live')" class="btn btn-success btn-sm" id="refreshBtn">
            <i class="fas fa-sync-alt"></i> <?= __('dashboard_live_refresh') ?>
        </button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-title"><?= __('dashboard_active_sites') ?></div>
            <div class="stat-card-icon" style="background:#e3f2fd;color:#1976d2;"><i class="fas fa-map-marker-alt"></i></div>
        </div>
        <div class="stat-card-value"><?= $stats['total_sites'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-title"><?= __('dashboard_users') ?></div>
            <div class="stat-card-icon" style="background:#f3e5f5;color:#7b1fa2;"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-card-value"><?= $stats['total_users'] ?></div>
    </div>
    <div class="stat-card live">
        <div class="stat-card-header">
            <div class="stat-card-title">🟢 <?= __('dashboard_valid') ?></div>
            <div class="stat-card-icon" style="background:#e8f5e9;color:#388e3c;"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="stat-card-value valid" id="liveValid"><?= (int)($voucherStats['valid']??0) ?></div>
        <div class="stat-card-sub" id="subValid"><?= $lastCronSync ? date('H:i', strtotime($lastCronSync)) : __('never') ?></div>
    </div>
    <div class="stat-card live">
        <div class="stat-card-header">
            <div class="stat-card-title">🟡 <?= __('dashboard_used') ?></div>
            <div class="stat-card-icon" style="background:#fff3e0;color:#f57c00;"><i class="fas fa-user-check"></i></div>
        </div>
        <div class="stat-card-value used" id="liveUsed"><?= (int)($voucherStats['used']??0) ?></div>
    </div>
    <div class="stat-card live">
        <div class="stat-card-header">
            <div class="stat-card-title">🔴 <?= __('dashboard_expired') ?></div>
            <div class="stat-card-icon" style="background:#ffebee;color:#d32f2f;"><i class="fas fa-times-circle"></i></div>
        </div>
        <div class="stat-card-value expired" id="liveExpired"><?= (int)($voucherStats['expired']??0) ?></div>
    </div>
    <div class="stat-card live">
        <div class="stat-card-header">
            <div class="stat-card-title">📊 <?= __('dashboard_total') ?></div>
            <div class="stat-card-icon" style="background:var(--bg-hover);color:var(--text-muted);"><i class="fas fa-ticket-alt"></i></div>
        </div>
        <div class="stat-card-value" id="liveTotal"><?= (int)($voucherStats['total']??0) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">🔴 <?= __('dashboard_vouchers_per_site') ?></h2>
        <span class="last-update" id="lastUpdate"><?= $lastCronSync ? date('d.m.Y H:i', strtotime($lastCronSync)) : __('never') ?></span>
    </div>
    <div class="card-body" style="padding:18px;">
        <div class="site-status" id="siteStatusContainer">
            <?php foreach ($sites as $site):
                $ss = $siteStats[$site['id']] ?? ['total'=>0,'valid'=>0,'used'=>0,'expired'=>0];
            ?>
            <div class="site-status-card" id="site-<?= $site['id'] ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <div class="site-status-name"><?= htmlspecialchars($site['name']) ?></div>
                    <span class="site-voucher-count <?= (int)$ss['total']===0 ? 'zero' : '' ?>" id="site-total-<?= $site['id'] ?>">
                        <?= (int)$ss['total'] ?>
                    </span>
                </div>
                <div class="site-status-stats">
                    <div class="site-stat"><div class="site-stat-value valid" id="site-valid-<?= $site['id'] ?>"><?= (int)$ss['valid'] ?></div><div class="site-stat-label"><?= __('status_valid') ?></div></div>
                    <div class="site-stat"><div class="site-stat-value used"  id="site-used-<?= $site['id'] ?>"><?= (int)$ss['used'] ?></div><div class="site-stat-label"><?= __('status_used') ?></div></div>
                    <div class="site-stat"><div class="site-stat-value expired" id="site-expired-<?= $site['id'] ?>"><?= (int)$ss['expired'] ?></div><div class="site-stat-label"><?= __('status_expired') ?></div></div>
                </div>
                <div class="site-error" id="site-error-<?= $site['id'] ?>" style="display:none;"></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($sites)): ?>
            <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-muted);">
                <i class="fas fa-map-marker-alt" style="font-size:32px;margin-bottom:15px;opacity:.3;display:block;"></i>
                <p><?= __('dashboard_no_data') ?></p>
                <a href="sites.php" class="btn btn-primary" style="margin-top:15px;"><i class="fas fa-plus"></i> <?= __('sites_add') ?></a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">📊 <?= __('dashboard_trend') ?></h2></div>
    <div class="card-body">
        <div class="chart-container"><canvas id="voucherChart"></canvas></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-bottom:28px;" class="two-col-grid">
    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><h2 class="card-title">🏆 <?= __('dashboard_top_users') ?></h2></div>
        <div class="card-body" style="padding:18px 22px;">
            <?php if (empty($topUsers)): ?>
                <div class="empty-state"><i class="fas fa-users"></i><p><?= __('dashboard_no_data') ?></p></div>
            <?php else: ?>
                <ul class="top-users-list">
                    <?php foreach ($topUsers as $u): ?>
                    <li>
                        <div class="user-info-row">
                            <div class="user-avatar-sm"><?= strtoupper(mb_substr($u['name'],0,1)) ?></div>
                            <div>
                                <div style="font-weight:600;font-size:14px;color:var(--text-primary);"><?= htmlspecialchars($u['name']) ?></div>
                                <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                        </div>
                        <span class="user-count"><?= $u['voucher_count'] ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><h2 class="card-title">📋 <?= __('dashboard_recent') ?></h2><a href="vouchers.php" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Live</a></div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($recentVouchers)): ?>
                <div class="empty-state"><i class="fas fa-ticket-alt"></i><p><?= __('dashboard_no_vouchers') ?></p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th><?= __('label_created') ?></th><th><?= __('label_code') ?></th><th><?= __('label_site') ?></th><th><?= __('label_status') ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($recentVouchers as $v): ?>
                        <tr>
                            <td style="font-size:12px;"><?= date('d.m H:i', strtotime($v['created_at'])) ?></td>
                            <td><code style="font-size:12px;background:var(--bg-hover);padding:3px 6px;border-radius:4px;"><?= htmlspecialchars($v['voucher_code']) ?></code></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($v['site_name']??'') ?></span></td>
                            <td>
                                <?php $st=$v['status']??'valid'; ?>
                                <span class="badge badge-<?= $st==='valid'?'success':($st==='used'?'warning':'danger') ?>"><?= __('status_'.$st) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- /main-content -->

<div id="toast-container"></div>
<script src="../assets/global.js"></script>
<script>
const ctx = document.getElementById('voucherChart').getContext('2d');
const chartData = <?= json_encode($chartData) ?>;
new Chart(ctx, {
    type: 'line',
    data: {
        labels: chartData.map(d => d.date),
        datasets: [{
            label: 'Vouchers',
            data: chartData.map(d => d.count),
            borderColor: '#667eea',
            backgroundColor: 'rgba(102,126,234,0.1)',
            tension: 0.4, fill: true,
            pointBackgroundColor: '#667eea', pointBorderColor: '#fff',
            pointBorderWidth: 2, pointRadius: 5, pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1, color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() } }, x: { ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() } } }
    }
});

let isLoading = false;
async function refreshData(mode='db') {
    if (isLoading) return;
    isLoading = true;
    const liveBadge = document.getElementById('liveBadge');
    const liveStatus = document.getElementById('liveStatus');
    const refreshBtn = document.getElementById('refreshBtn');
    liveBadge.className = 'live-badge loading';
    liveStatus.textContent = '...';
    refreshBtn.disabled = true;
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const url = `index.php?ajax_stats=1${mode==='live'?'&sync=1':''}`;
        const result = await fetch(url).then(r=>r.json());
        if (result.success) {
            document.getElementById('liveValid').textContent = result.total.valid;
            document.getElementById('liveUsed').textContent  = result.total.used;
            document.getElementById('liveExpired').textContent = result.total.expired;
            document.getElementById('liveTotal').textContent  = result.total.total;
            document.getElementById('subValid').textContent   = mode==='live' ? result.timestamp : (result.last_sync||'');
            result.sites.forEach(site => {
                const totalBadge = document.getElementById(`site-total-${site.site_id}`);
                const validEl    = document.getElementById(`site-valid-${site.site_id}`);
                const usedEl     = document.getElementById(`site-used-${site.site_id}`);
                const expiredEl  = document.getElementById(`site-expired-${site.site_id}`);
                const errorEl    = document.getElementById(`site-error-${site.site_id}`);
                if (!totalBadge) return;
                if (site.error) {
                    totalBadge.innerHTML = '!'; totalBadge.className = 'site-voucher-count zero';
                    validEl.textContent = usedEl.textContent = expiredEl.textContent = '-';
                    errorEl.textContent = site.error; errorEl.style.display = 'block';
                } else {
                    totalBadge.textContent = site.stats.total;
                    totalBadge.className = 'site-voucher-count' + (site.stats.total===0?' zero':'');
                    validEl.textContent = site.stats.valid;
                    usedEl.textContent  = site.stats.used;
                    expiredEl.textContent = site.stats.expired;
                    errorEl.style.display = 'none';
                }
            });
            document.getElementById('lastUpdate').textContent = mode==='live' ? result.timestamp : (result.last_sync||'');
            liveBadge.className = 'live-badge'; liveStatus.textContent = mode==='live' ? '✓ Live' : 'DB';
        } else { throw new Error(result.message); }
    } catch(e) {
        liveBadge.className = 'live-badge error'; liveStatus.textContent = 'Fehler';
    }
    isLoading = false;
    refreshBtn.disabled = false;
    refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> <?= __('dashboard_live_refresh') ?>';
}

// Responsive two-column grid
const twoCol = document.querySelector('.two-col-grid');
function checkGrid() { if (twoCol) twoCol.style.gridTemplateColumns = window.innerWidth < 900 ? '1fr' : '1fr 1fr'; }
checkGrid(); window.addEventListener('resize', checkGrid);
</script>
</body>
</html>
