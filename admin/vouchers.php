<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// CSV-Export
if (isset($_GET['export_csv']) && isset($_GET['site_id'])) {
    if (!$auth->validateCsrfToken($_GET['token'] ?? '')) { http_response_code(403); exit(__('error_csrf')); }
    $siteId = (int)$_GET['site_id'];
    $site = $db->fetchOne("SELECT * FROM sites WHERE id=? AND is_active=1", [$siteId]);
    if (!$site) { http_response_code(404); exit; }
    $rows = $db->fetchAll("SELECT voucher_code,voucher_name,max_uses,expire_minutes,status,used_count,created_at,expires_at FROM vouchers WHERE site_id=? ORDER BY created_at DESC", [$siteId]);
    $filename = 'vouchers_' . preg_replace('/[^a-z0-9]/i','_',$site['name']) . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['Code','Name','Max. Geräte','Gültigkeit (Min)','Status','Genutzt','Erstellt','Läuft ab'],';');
    foreach ($rows as $r) {
        fputcsv($out,[$r['voucher_code'],$r['voucher_name'],$r['max_uses'],$r['expire_minutes'],$r['status'],$r['used_count'],$r['created_at'],$r['expires_at']??''],';');
    }
    fclose($out); exit;
}

// AJAX: Vouchers laden
if (isset($_GET['ajax_get_vouchers']) && isset($_GET['site_id'])) {
    header('Content-Type: application/json');
    try {
        $siteId = (int)$_GET['site_id'];
        $syncFirst = isset($_GET['sync']) && $_GET['sync']=='1';
        $site = $db->fetchOne("SELECT * FROM sites WHERE id=? AND is_active=1", [$siteId]);
        if (!$site) { echo json_encode(['success'=>false,'message'=>__('error_site_not_found')]); exit; }
        if ($syncFirst) {
            try {
                $ctrl = new UniFiController($site['unifi_controller_url'],$site['unifi_username'],$site['unifi_password'],$site['site_id']);
                $ctrl->syncVouchersToDatabase($db,$siteId);
                $db->execute("INSERT INTO settings (setting_key,setting_value) VALUES ('last_cron_sync',NOW()) ON DUPLICATE KEY UPDATE setting_value=NOW()");
            } catch (Exception $e) { error_log("Sync error: ".$e->getMessage()); }
        }
        $dbVouchers = $db->fetchAll("SELECT * FROM vouchers WHERE site_id=? ORDER BY created_at DESC", [$siteId]);
        $vouchers = [];
        foreach ($dbVouchers as $v) {
            $vouchers[] = [
                '_id'            => $v['unifi_voucher_id'] ?? $v['id'],
                'code'           => str_replace('-','',$v['voucher_code']),
                'formatted_code' => $v['voucher_code'],
                'note'           => $v['voucher_name'],
                'quota'          => (int)$v['max_uses'],
                'used'           => (int)($v['used_count']??0),
                'duration'       => (int)$v['expire_minutes'],
                'create_time'    => strtotime($v['created_at']),
                'expire_time'    => $v['expires_at'] ? strtotime($v['expires_at']) : 0,
                'status'         => $v['status']??'valid',
                'db_id'          => $v['id'],
            ];
        }
        $lastSync = $db->getSetting('last_cron_sync','');
        echo json_encode(['success'=>true,'vouchers'=>$vouchers,'site_name'=>$site['name'],'count'=>count($vouchers),'synced'=>$syncFirst,'last_sync'=>$lastSync?date('d.m.Y H:i:s',strtotime($lastSync)):null]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Fehler: '.$e->getMessage()]);
    }
    exit;
}

// AJAX: Voucher löschen
if (isset($_POST['ajax_delete']) && isset($_POST['voucher_id']) && isset($_POST['site_id'])) {
    header('Content-Type: application/json');
    if (!$auth->validateCsrfToken($_POST['csrf_token']??'')) { echo json_encode(['success'=>false,'message'=>__('error_csrf')]); exit; }
    try {
        $voucherId = $_POST['voucher_id'];
        $siteId    = (int)$_POST['site_id'];
        $site = $db->fetchOne("SELECT * FROM sites WHERE id=? AND is_active=1", [$siteId]);
        if (!$site) { echo json_encode(['success'=>false,'message'=>__('error_site_not_found')]); exit; }
        $ctrl = new UniFiController($site['unifi_controller_url'],$site['unifi_username'],$site['unifi_password'],$site['site_id']);
        if ($ctrl->deleteVoucher($voucherId)) {
            $db->execute("DELETE FROM vouchers WHERE unifi_voucher_id=? AND site_id=?", [$voucherId,$siteId]);
            echo json_encode(['success'=>true,'message'=>'Voucher erfolgreich gelöscht!']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Voucher konnte nicht gelöscht werden']);
        }
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Fehler: '.$e->getMessage()]);
    }
    exit;
}

$sites = $db->fetchAll("SELECT * FROM sites WHERE is_active=1 ORDER BY name");
$siteStats = [];
foreach ($sites as $site) {
    $siteStats[$site['id']] = $db->fetchOne("SELECT COUNT(*) as total, SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END) as valid, SUM(CASE WHEN status='used' THEN 1 ELSE 0 END) as used, SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) as expired FROM vouchers WHERE site_id=?", [$site['id']]);
}
$lastCronSync = $db->getSetting('last_cron_sync','');
$currentPage  = 'vouchers';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('vouchers_title') ?> – <?= htmlspecialchars($appTitle) ?></title>
<?php include __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
    .page-header { margin-bottom: 28px; }
    .page-title { font-size: 26px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 12px; }
    .page-subtitle { color: var(--text-muted); font-size: 14px; margin-top: 4px; }
    .live-indicator { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--success); }
    .live-indicator .dot { width: 8px; height: 8px; background: var(--success); border-radius: 50%; animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1}50%{opacity:.5} }
    .card { background: var(--bg-card); border-radius: 14px; box-shadow: 0 2px 10px var(--shadow); border: 1px solid var(--border-color); margin-bottom: 22px; overflow: hidden; }
    .card-header { padding: 18px 22px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .card-title { font-size: 16px; font-weight: 600; color: var(--text-primary); }
    .card-body { padding: 22px; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap: 16px; margin-bottom: 22px; }
    .stat-card { background: var(--bg-card); padding: 18px; border-radius: 12px; box-shadow: 0 2px 8px var(--shadow); border: 1px solid var(--border-color); }
    .stat-label { color: var(--text-muted); font-size: 13px; margin-bottom: 6px; }
    .stat-value { font-size: 28px; font-weight: 700; color: var(--text-primary); }
    .stat-value.valid { color: var(--success); }
    .stat-value.used  { color: var(--warning); }
    .stat-value.expired { color: var(--danger); }
    .site-selector { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .site-selector select { padding: 10px 14px; border: 2px solid var(--border-color); border-radius: 8px; font-size: 14px; min-width: 220px; cursor: pointer; background: var(--bg-input); color: var(--text-primary); }
    .site-selector select:focus { outline: none; border-color: var(--accent); }
    .search-bar { padding: 10px 14px; border: 2px solid var(--border-color); border-radius: 8px; font-size: 14px; background: var(--bg-input); color: var(--text-primary); min-width: 200px; }
    .search-bar:focus { outline: none; border-color: var(--accent); }
    .btn { padding: 9px 18px; border-radius: 8px; border: none; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: all .2s; font-size: 13px; }
    .btn-primary { background: var(--accent); color: white; }
    .btn-primary:hover { background: var(--accent-hover); }
    .btn-secondary { background: var(--bg-hover); color: var(--text-secondary); border: 1px solid var(--border-color); }
    .btn-secondary:hover { background: var(--border-color); }
    .btn-danger { background: var(--danger); color: white; }
    .btn-danger:hover { opacity: .88; }
    .btn-success { background: var(--success); color: white; }
    .btn-sm { padding: 5px 11px; font-size: 12px; }
    .btn:disabled { opacity: .55; cursor: not-allowed; }
    .table-container { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; }
    .table th { text-align: left; padding: 11px 14px; background: var(--bg-table-head); color: var(--text-muted); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
    .table td { padding: 13px 14px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); font-size: 13px; }
    .table tr:last-child td { border-bottom: none; }
    .table tr:hover { background: var(--bg-hover); }
    .table tr.deleting { opacity: .45; pointer-events: none; }
    .badge { display: inline-block; padding: 3px 9px; border-radius: 5px; font-size: 11px; font-weight: 500; }
    .badge-success { background: #d4edda; color: #155724; }
    .badge-warning { background: #fff3cd; color: #856404; }
    .badge-danger  { background: #f8d7da; color: #721c24; }
    .badge-info    { background: var(--bg-badge-info); color: var(--text-badge-info); }
    code { background: var(--bg-hover); padding: 4px 8px; border-radius: 4px; font-family: 'Courier New', monospace; font-size: 12px; letter-spacing: 1px; }
    .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
    .empty-state i { font-size: 42px; margin-bottom: 18px; opacity: .3; display: block; }
    .loading { display: flex; align-items: center; justify-content: center; padding: 60px; color: var(--text-muted); gap: 12px; }
    .loading i { font-size: 22px; animation: spin 1s linear infinite; }
    @keyframes spin { from{transform:rotate(0)}to{transform:rotate(360deg)} }
    .filter-row { display: flex; gap: 8px; flex-wrap: wrap; }
    .filter-btn { padding: 7px 14px; border: 2px solid var(--border-color); background: var(--bg-card); color: var(--text-secondary); border-radius: 8px; cursor: pointer; font-size: 13px; transition: all .2s; }
    .filter-btn:hover { border-color: var(--accent); }
    .filter-btn.active { border-color: var(--accent); background: var(--accent); color: white; }
    .usage-info { display: flex; align-items: center; gap: 6px; }
    .usage-bar { width: 50px; height: 5px; background: var(--border-color); border-radius: 3px; overflow: hidden; }
    .usage-bar-fill { height: 100%; background: var(--accent); border-radius: 3px; }
    .voucher-note { max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .no-sites-warning { background: #fff3cd; border: 1px solid #ffc107; border-radius: 12px; padding: 30px; text-align: center; color: #856404; }
    @media(max-width:768px){ .main-content{ margin-left:0!important; } }
</style>

<div class="page-header">
    <h1 class="page-title">
        <?= __('vouchers_title') ?>
        <span class="live-indicator"><span class="dot"></span>LIVE</span>
    </h1>
    <p class="page-subtitle"><?= __('vouchers_subtitle') ?></p>
</div>

<?php if (empty($sites)): ?>
<div class="no-sites-warning">
    <i class="fas fa-exclamation-triangle" style="font-size:48px;margin-bottom:15px;opacity:.7;display:block;"></i>
    <h3><?= __('vouchers_no_sites') ?></h3>
    <a href="sites.php" class="btn btn-primary" style="margin-top:20px;"><i class="fas fa-plus"></i> <?= __('nav_sites') ?></a>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= __('vouchers_select_site') ?></h2>
        <span style="font-size:12px;color:var(--text-muted);"><?= __('vouchers_last_sync') ?>: <?= $lastCronSync ? date('d.m.Y H:i', strtotime($lastCronSync)) : __('never') ?></span>
    </div>
    <div class="card-body">
        <div class="site-selector">
            <select id="siteSelect" onchange="loadVouchers(false)">
                <option value=""><?= __('vouchers_select_hint') ?></option>
                <?php foreach ($sites as $site):
                    $ss = $siteStats[$site['id']] ?? ['total'=>0];
                ?>
                <option value="<?= $site['id'] ?>" data-name="<?= htmlspecialchars($site['name']) ?>">
                    <?= htmlspecialchars($site['name']) ?> (<?= (int)$ss['total'] ?> Vouchers)
                </option>
                <?php endforeach; ?>
            </select>
            <input type="search" id="searchInput" class="search-bar" placeholder="<?= __('vouchers_search') ?>" oninput="filterAndRender()" style="display:none;">
            <button id="refreshBtn" class="btn btn-success" onclick="loadVouchers(true)" disabled>
                <i class="fas fa-sync-alt"></i> <?= __('btn_refresh') ?>
            </button>
        </div>
    </div>
</div>

<div id="statsContainer" style="display:none;">
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label"><?= __('dashboard_total') ?></div><div class="stat-value" id="statTotal">0</div></div>
        <div class="stat-card"><div class="stat-label"><?= __('status_valid') ?></div><div class="stat-value valid" id="statValid">0</div></div>
        <div class="stat-card"><div class="stat-label"><?= __('status_used') ?></div><div class="stat-value used" id="statUsed">0</div></div>
        <div class="stat-card"><div class="stat-label"><?= __('status_expired') ?></div><div class="stat-value expired" id="statExpired">0</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title" id="voucherListTitle">Vouchers</h2>
        <a id="csvExportBtn" style="display:none;" class="btn btn-secondary btn-sm" href="#">
            <i class="fas fa-download"></i> <?= __('btn_export_csv') ?>
        </a>
    </div>
    <div class="card-body" style="padding:0;">
        <div id="voucherContent">
            <div class="empty-state"><i class="fas fa-ticket-alt"></i><p><?= __('vouchers_select_hint') ?></p></div>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /main-content -->

<div id="toast-container"></div>
<script src="../assets/global.js"></script>
<script>
const csrfToken = '<?= $auth->getCsrfToken() ?>';
let currentSiteId = null;
let allVouchers   = [];
let currentFilter = 'all';
let currentPage   = 1;
let searchQuery   = '';
const PAGE_SIZE   = 50;

async function loadVouchers(syncFirst=false) {
    const select = document.getElementById('siteSelect');
    const siteId = select.value;
    const refreshBtn = document.getElementById('refreshBtn');
    const searchInput = document.getElementById('searchInput');

    if (!siteId) {
        document.getElementById('voucherContent').innerHTML = `<div class="empty-state"><i class="fas fa-ticket-alt"></i><p><?= addslashes(__('vouchers_select_hint')) ?></p></div>`;
        document.getElementById('statsContainer').style.display = 'none';
        searchInput.style.display = 'none';
        refreshBtn.disabled = true;
        return;
    }

    currentSiteId = siteId;
    refreshBtn.disabled = false;
    refreshBtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${syncFirst ? '<?= addslashes(__('btn_refresh')) ?>' : '<?= addslashes(__('btn_refresh')) ?>'}`;

    document.getElementById('voucherContent').innerHTML = `<div class="loading"><i class="fas fa-spinner"></i><span>${syncFirst ? 'Synchronisiere...' : 'Lade...'}</span></div>`;

    try {
        const result = await fetch(`vouchers.php?ajax_get_vouchers=1&site_id=${siteId}${syncFirst?'&sync=1':''}`).then(r=>r.json());
        if (result.success) {
            allVouchers = result.vouchers;
            currentPage = 1;
            searchQuery = '';
            if (searchInput) { searchInput.style.display = ''; searchInput.value = ''; }
            document.getElementById('voucherListTitle').textContent = `Vouchers – ${result.site_name} (${result.count})`;
            updateStats();
            filterAndRender();
            document.getElementById('statsContainer').style.display = 'block';
            const csvBtn = document.getElementById('csvExportBtn');
            csvBtn.style.display = 'inline-flex';
            csvBtn.href = `vouchers.php?export_csv=1&site_id=${siteId}&token=${csrfToken}`;
            if (syncFirst) showToast('success', '<?= addslashes(__('btn_refresh')) ?>', `${result.count} Vouchers geladen`);
        } else {
            document.getElementById('voucherContent').innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle" style="color:var(--danger)"></i><p>${result.message}</p></div>`;
            document.getElementById('statsContainer').style.display = 'none';
        }
    } catch(e) {
        document.getElementById('voucherContent').innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle" style="color:var(--danger)"></i><p>Verbindungsfehler: ${e.message}</p></div>`;
        document.getElementById('statsContainer').style.display = 'none';
    }
    refreshBtn.disabled = false;
    refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> <?= addslashes(__('btn_refresh')) ?>';
}

function updateStats() {
    document.getElementById('statTotal').textContent   = allVouchers.length;
    document.getElementById('statValid').textContent   = allVouchers.filter(v=>v.status==='valid').length;
    document.getElementById('statUsed').textContent    = allVouchers.filter(v=>v.status==='used').length;
    document.getElementById('statExpired').textContent = allVouchers.filter(v=>v.status==='expired').length;
}

function setFilter(filter) {
    currentFilter = filter;
    currentPage   = 1;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.toggle('active', b.dataset.filter===filter));
    filterAndRender();
}

function filterAndRender() {
    searchQuery = (document.getElementById('searchInput')?.value||'').toLowerCase();
    currentPage = 1;
    renderVouchers();
}

function setPage(page) { currentPage = page; renderVouchers(); }

function renderVouchers() {
    let vouchers = allVouchers;
    if (currentFilter !== 'all') vouchers = vouchers.filter(v => v.status === currentFilter);
    if (searchQuery) vouchers = vouchers.filter(v =>
        (v.formatted_code||'').toLowerCase().includes(searchQuery) ||
        (v.note||'').toLowerCase().includes(searchQuery)
    );

    const totalPages  = Math.ceil(vouchers.length / PAGE_SIZE);
    if (currentPage > totalPages && totalPages > 0) currentPage = totalPages;
    const pageVouchers = vouchers.slice((currentPage-1)*PAGE_SIZE, currentPage*PAGE_SIZE);

    if (vouchers.length === 0) {
        document.getElementById('voucherContent').innerHTML = `<div class="empty-state"><i class="fas fa-ticket-alt"></i><p><?= addslashes(__('vouchers_none')) ?></p></div>`;
        return;
    }

    const validCnt   = allVouchers.filter(v=>v.status==='valid').length;
    const usedCnt    = allVouchers.filter(v=>v.status==='used').length;
    const expiredCnt = allVouchers.filter(v=>v.status==='expired').length;

    let html = `<div style="padding:14px 22px;border-bottom:1px solid var(--border-color);display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <div class="filter-row">
            <button class="filter-btn ${currentFilter==='all'?'active':''}" data-filter="all" onclick="setFilter('all')"><?= __('vouchers_filter_all') ?> (${allVouchers.length})</button>
            <button class="filter-btn ${currentFilter==='valid'?'active':''}" data-filter="valid" onclick="setFilter('valid')"><?= __('vouchers_filter_valid') ?> (${validCnt})</button>
            <button class="filter-btn ${currentFilter==='used'?'active':''}" data-filter="used" onclick="setFilter('used')"><?= __('vouchers_filter_used') ?> (${usedCnt})</button>
            <button class="filter-btn ${currentFilter==='expired'?'active':''}" data-filter="expired" onclick="setFilter('expired')"><?= __('vouchers_filter_expired') ?> (${expiredCnt})</button>
        </div>
    </div>
    <div class="table-container"><table class="table">
        <thead><tr>
            <th><?= __('label_created') ?></th>
            <th><?= __('label_code') ?></th>
            <th><?= __('label_note') ?></th>
            <th><?= __('label_status') ?></th>
            <th><?= __('label_usage') ?></th>
            <th><?= __('label_expires') ?></th>
            <th><?= __('label_actions') ?></th>
        </tr></thead><tbody>`;

    pageVouchers.forEach(v => {
        const createDate = new Date(v.create_time*1000);
        const remaining  = v.status==='valid' && v.expire_time ? (() => {
            const diff = v.expire_time - Math.floor(Date.now()/1000);
            if (diff <= 0) return '';
            const h = Math.floor(diff/3600), m = Math.floor((diff%3600)/60);
            return h > 0 ? `${h}h ${m}m` : `${m}m`;
        })() : '';
        const usagePct   = v.quota > 0 ? Math.min(100, (v.used/v.quota)*100) : 0;
        const statusBadge = v.status==='valid'
            ? `<span class="badge badge-success"><i class="fas fa-check"></i> <?= __('status_valid') ?></span>`
            : v.status==='used'
            ? `<span class="badge badge-warning"><i class="fas fa-user-check"></i> <?= __('status_used') ?></span>`
            : `<span class="badge badge-danger"><i class="fas fa-times"></i> <?= __('status_expired') ?></span>`;

        html += `<tr id="voucher-${v._id}">
            <td><strong>${createDate.toLocaleDateString('de-DE')}</strong><br><small style="color:var(--text-muted)">${createDate.toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'})}</small></td>
            <td><code onclick="copyToClipboard('${escapeHtml(v.formatted_code||'')}','Kopiert!')" title="Kopieren" style="cursor:pointer">${escapeHtml(v.formatted_code||'')}</code></td>
            <td class="voucher-note" title="${escapeHtml(v.note||'-')}">${escapeHtml(v.note||'-')}</td>
            <td>${statusBadge}</td>
            <td><div class="usage-info"><span>${v.used}/${v.quota>0?v.quota:'∞'}</span>${v.quota>0?`<div class="usage-bar"><div class="usage-bar-fill" style="width:${usagePct}%"></div></div>`:''}</div></td>
            <td>${remaining?`<span style="color:var(--success)"><i class="fas fa-clock"></i> ${remaining}</span><br>`:''}<small style="color:var(--text-muted)">${v.duration} Min.</small></td>
            <td><button onclick="deleteVoucher('${v._id}')" class="btn btn-danger btn-sm" title="<?= __('btn_delete') ?>"><i class="fas fa-trash"></i></button></td>
        </tr>`;
    });

    html += `</tbody></table></div>`;

    if (totalPages > 1) {
        html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-top:1px solid var(--border-color);">
            <span style="font-size:13px;color:var(--text-muted);">${<?= json_encode(__('vouchers_page_of')) ?>
                .replace('{current}',currentPage).replace('{total}',totalPages)} (${vouchers.length})</span>
            <div style="display:flex;gap:5px;">
                <button class="btn btn-secondary btn-sm" onclick="setPage(${currentPage-1})" ${currentPage<=1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
        for (let p=Math.max(1,currentPage-2); p<=Math.min(totalPages,currentPage+2); p++) {
            html += `<button class="btn btn-sm ${p===currentPage?'btn-primary':'btn-secondary'}" onclick="setPage(${p})">${p}</button>`;
        }
        html += `<button class="btn btn-secondary btn-sm" onclick="setPage(${currentPage+1})" ${currentPage>=totalPages?'disabled':''}><i class="fas fa-chevron-right"></i></button>
            </div></div>`;
    }

    document.getElementById('voucherContent').innerHTML = html;
}

function escapeHtml(t) { const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

async function deleteVoucher(voucherId) {
    if (!confirm('Voucher wirklich löschen?')) return;
    const row = document.getElementById(`voucher-${voucherId}`);
    if (row) row.classList.add('deleting');
    try {
        const fd = new FormData();
        fd.append('ajax_delete','1'); fd.append('voucher_id',voucherId);
        fd.append('site_id',currentSiteId); fd.append('csrf_token',csrfToken);
        const result = await fetch('vouchers.php',{method:'POST',body:fd}).then(r=>r.json());
        if (result.success) {
            allVouchers = allVouchers.filter(v=>v._id!==voucherId);
            updateStats(); filterAndRender();
            showToast('success','<?= addslashes(__('btn_delete')) ?>',result.message);
        } else {
            if (row) row.classList.remove('deleting');
            showToast('error','Fehler',result.message);
        }
    } catch(e) {
        if (row) row.classList.remove('deleting');
        showToast('error','Fehler','Unerwarteter Fehler');
    }
}
</script>
</body>
</html>
