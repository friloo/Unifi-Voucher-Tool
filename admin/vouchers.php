<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/UniFiController.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

// CSV-Export
if (isset($_GET['export_csv']) && isset($_GET['site_id'])) {
    if (!$auth->validateCsrfToken($_GET['token'] ?? '')) {
        http_response_code(403);
        exit('Ungültiges Token');
    }
    $siteId = (int)$_GET['site_id'];
    $site = $db->fetchOne("SELECT * FROM sites WHERE id = ? AND is_active = 1", [$siteId]);
    if (!$site) { http_response_code(404); exit('Site nicht gefunden'); }

    $rows = $db->fetchAll(
        "SELECT voucher_code, voucher_name, max_uses, expire_minutes, status, used_count, created_at, expires_at
         FROM vouchers WHERE site_id = ? ORDER BY created_at DESC",
        [$siteId]
    );

    $filename = 'vouchers_' . preg_replace('/[^a-z0-9]/i', '_', $site['name']) . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM für Excel
    fputcsv($out, ['Code', 'Name', 'Max. Geräte', 'Gültigkeit (Min)', 'Status', 'Genutzt', 'Erstellt', 'Läuft ab'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['voucher_code'], $r['voucher_name'], $r['max_uses'],
            $r['expire_minutes'], $r['status'], $r['used_count'],
            $r['created_at'], $r['expires_at'] ?? ''
        ], ';');
    }
    fclose($out);
    exit;
}

// AJAX: Voucher abrufen (immer aus DB, optional vorher Live-Sync)
if (isset($_GET['ajax_get_vouchers']) && isset($_GET['site_id'])) {
    header('Content-Type: application/json');

    try {
        $siteId = (int)$_GET['site_id'];
        $syncFirst = isset($_GET['sync']) && $_GET['sync'] == '1';
        $site = $db->fetchOne("SELECT * FROM sites WHERE id = ? AND is_active = 1", [$siteId]);

        if (!$site) {
            echo json_encode(['success' => false, 'message' => 'Site nicht gefunden oder inaktiv']);
            exit;
        }

        // Bei sync=1: Erst Live-Daten holen und in DB speichern
        if ($syncFirst) {
            try {
                $controller = new UniFiController(
                    $site['unifi_controller_url'],
                    $site['unifi_username'],
                    $site['unifi_password'],
                    $site['site_id']
                );
                $controller->syncVouchersToDatabase($db, $siteId);

                // Last sync time aktualisieren
                $db->execute(
                    "INSERT INTO settings (setting_key, setting_value) VALUES ('last_cron_sync', NOW())
                     ON DUPLICATE KEY UPDATE setting_value = NOW()"
                );
            } catch (Exception $e) {
                // Sync-Fehler loggen, aber trotzdem DB-Daten zurückgeben
                error_log("Sync error for site {$siteId}: " . $e->getMessage());
            }
        }

        // Immer aus Datenbank abrufen
        $dbVouchers = $db->fetchAll(
            "SELECT * FROM vouchers WHERE site_id = ? ORDER BY created_at DESC",
            [$siteId]
        );

        $vouchers = [];
        foreach ($dbVouchers as $v) {
            $expireTime = $v['expires_at'] ? strtotime($v['expires_at']) : 0;
            $createTime = strtotime($v['created_at']);

            $vouchers[] = [
                '_id' => $v['unifi_voucher_id'] ?? $v['id'],
                'code' => str_replace('-', '', $v['voucher_code']),
                'formatted_code' => $v['voucher_code'],
                'note' => $v['voucher_name'],
                'quota' => (int)$v['max_uses'],
                'used' => (int)($v['used_count'] ?? 0),
                'duration' => (int)$v['expire_minutes'],
                'create_time' => $createTime,
                'expire_time' => $expireTime,
                'status' => $v['status'] ?? 'valid',
                'db_id' => $v['id']
            ];
        }

        $lastSync = $db->getSetting('last_cron_sync', '');

        echo json_encode([
            'success' => true,
            'vouchers' => $vouchers,
            'site_name' => $site['name'],
            'count' => count($vouchers),
            'synced' => $syncFirst,
            'last_sync' => $lastSync ? date('d.m.Y H:i:s', strtotime($lastSync)) : null
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
    }
    exit;
}

// AJAX: Voucher löschen
if (isset($_POST['ajax_delete']) && isset($_POST['voucher_id']) && isset($_POST['site_id'])) {
    header('Content-Type: application/json');

    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Ungültiges Sicherheits-Token']);
        exit;
    }

    try {
        $voucherId = $_POST['voucher_id']; // UniFi _id (String)
        $siteId = (int)$_POST['site_id'];

        $site = $db->fetchOne("SELECT * FROM sites WHERE id = ? AND is_active = 1", [$siteId]);

        if (!$site) {
            echo json_encode(['success' => false, 'message' => 'Site nicht gefunden oder inaktiv']);
            exit;
        }

        $controller = new UniFiController(
            $site['unifi_controller_url'],
            $site['unifi_username'],
            $site['unifi_password'],
            $site['site_id']
        );

        $result = $controller->deleteVoucher($voucherId);

        if ($result) {
            // Auch aus Datenbank löschen
            $db->execute("DELETE FROM vouchers WHERE unifi_voucher_id = ? AND site_id = ?", [$voucherId, $siteId]);
            echo json_encode(['success' => true, 'message' => 'Voucher erfolgreich gelöscht!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Voucher konnte nicht gelöscht werden']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
    }
    exit;
}

// Alle aktiven Sites abrufen
$sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1 ORDER BY name");

// Voucher-Statistiken aus DB pro Site
$siteStats = [];
foreach ($sites as $site) {
    $stats = $db->fetchOne(
        "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) as valid,
            SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as used,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
         FROM vouchers WHERE site_id = ?",
        [$site['id']]
    );
    $siteStats[$site['id']] = $stats;
}

// Letzte Synchronisation
$lastCronSync = $db->getSetting('last_cron_sync', '');

$currentUser = $auth->getCurrentUser();
$faviconUrl = $db->getSetting('favicon_url', '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Voucher-Verwaltung - <?= htmlspecialchars($appTitle) ?></title>
    <?php if ($faviconUrl): ?>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fa;
        }
        .header {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 0 30px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .header-title { font-size: 20px; font-weight: 600; color: #333; }
        .sidebar {
            position: fixed;
            left: 0;
            top: 70px;
            bottom: 0;
            width: 260px;
            background: white;
            border-right: 1px solid #e0e0e0;
            padding: 30px 0;
        }
        .sidebar-nav { list-style: none; }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 30px;
            color: #666;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 15px;
        }
        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: #f8f9fa;
            color: #667eea;
        }
        .sidebar-nav i { width: 20px; text-align: center; }
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: calc(100vh - 70px);
        }
        .page-header {
            margin-bottom: 30px;
        }
        .page-title { font-size: 28px; font-weight: 600; color: #333; margin-bottom: 10px; }
        .page-subtitle { color: #666; font-size: 14px; }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            font-size: 14px;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover { background: #5568d3; }
        .btn-secondary {
            background: #f8f9fa;
            color: #666;
            border: 1px solid #e0e0e0;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
        }
        .stat-label {
            color: #666;
            font-size: 13px;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
        }
        .stat-value.valid { color: #28a745; }
        .stat-value.used { color: #ffc107; }
        .stat-value.expired { color: #dc3545; }
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-title { font-size: 18px; font-weight: 600; color: #333; }
        .card-body { padding: 25px; }
        .site-selector {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        .site-selector select {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 250px;
            cursor: pointer;
        }
        .site-selector select:focus {
            outline: none;
            border-color: #667eea;
        }
        .table-container {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th {
            text-align: left;
            padding: 12px 15px;
            background: #f8f9fa;
            color: #666;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
            font-size: 14px;
        }
        .table tr:last-child td {
            border-bottom: none;
        }
        .table tr:hover {
            background: #f8f9fa;
        }
        .table tr.deleting {
            opacity: 0.5;
            pointer-events: none;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        code {
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            letter-spacing: 1px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            color: #666;
        }
        .loading i {
            font-size: 24px;
            margin-right: 15px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .refresh-btn {
            background: #28a745;
            color: white;
        }
        .refresh-btn:hover {
            background: #218838;
        }
        .refresh-btn.loading-state {
            pointer-events: none;
            opacity: 0.7;
        }
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #28a745;
            margin-left: 15px;
        }
        .live-indicator .dot {
            width: 8px;
            height: 8px;
            background: #28a745;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 1000;
            transform: translateY(150%);
            transition: transform 0.3s ease-in-out;
            min-width: 300px;
        }
        .toast.show {
            transform: translateY(0);
        }
        .toast.success {
            border-left: 4px solid #28a745;
        }
        .toast.error {
            border-left: 4px solid #dc3545;
        }
        .toast-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .toast.success .toast-icon {
            background: #d4edda;
            color: #28a745;
        }
        .toast.error .toast-icon {
            background: #f8d7da;
            color: #dc3545;
        }
        .toast-content {
            flex: 1;
        }
        .toast-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }
        .toast-message {
            font-size: 13px;
            color: #666;
        }
        .toast-close {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 20px;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .toast-close:hover {
            background: #f0f0f0;
            color: #333;
        }

        .filter-row {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: 8px 16px;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        .filter-btn:hover {
            border-color: #667eea;
        }
        .filter-btn.active {
            border-color: #667eea;
            background: #667eea;
            color: white;
        }
        .voucher-note {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .usage-info {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .usage-bar {
            width: 60px;
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        .usage-bar-fill {
            height: 100%;
            background: #667eea;
            border-radius: 3px;
        }
        .no-sites-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            color: #856404;
        }
        .no-sites-warning i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-title">
            <i class="fas fa-shield-alt"></i> Administration
        </div>
        <a href="../index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zurück
        </a>
    </div>

    <div class="sidebar">
        <nav class="sidebar-nav">
            <ul>
                <li><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="sites.php"><i class="fas fa-map-marker-alt"></i> Sites verwalten</a></li>
                <li><a href="users.php"><i class="fas fa-users"></i> Benutzer verwalten</a></li>
                <li><a href="vouchers.php" class="active"><i class="fas fa-ticket-alt"></i> Live Vouchers</a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> Einstellungen</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">
                Live Voucher-Verwaltung
                <span class="live-indicator">
                    <span class="dot"></span>
                    LIVE
                </span>
            </h1>
            <p class="page-subtitle">Vouchers werden direkt vom UniFi Controller abgerufen</p>
        </div>

        <?php if (empty($sites)): ?>
        <div class="no-sites-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Keine aktiven Sites vorhanden</h3>
            <p style="margin-top: 10px;">Bitte fügen Sie zuerst eine Site hinzu oder aktivieren Sie eine vorhandene.</p>
            <a href="sites.php" class="btn btn-primary" style="margin-top: 20px;">
                <i class="fas fa-plus"></i> Sites verwalten
            </a>
        </div>
        <?php else: ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Site auswählen</h2>
                <span style="font-size: 12px; color: #999;">Letzte Sync: <?= $lastCronSync ? date('d.m.Y H:i', strtotime($lastCronSync)) : 'noch nie' ?></span>
            </div>
            <div class="card-body">
                <div class="site-selector">
                    <select id="siteSelect" onchange="loadVouchers(false)">
                        <option value="">-- Site auswählen --</option>
                        <?php foreach ($sites as $site):
                            $ss = $siteStats[$site['id']] ?? ['total' => 0];
                        ?>
                        <option value="<?= $site['id'] ?>" data-name="<?= htmlspecialchars($site['name']) ?>">
                            <?= htmlspecialchars($site['name']) ?> (<?= (int)$ss['total'] ?> Vouchers)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button id="refreshBtn" class="btn refresh-btn" onclick="loadVouchers(true)" disabled title="Live vom Controller abrufen und in DB synchronisieren">
                        <i class="fas fa-sync-alt"></i> Aktualisieren
                    </button>
                </div>
            </div>
        </div>

        <div id="statsContainer" style="display: none;">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Gesamt</div>
                    <div class="stat-value" id="statTotal">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Gültig</div>
                    <div class="stat-value valid" id="statValid">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Verwendet</div>
                    <div class="stat-value used" id="statUsed">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Abgelaufen</div>
                    <div class="stat-value expired" id="statExpired">0</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title" id="voucherListTitle">Vouchers</h2>
                <a id="csvExportBtn" style="display:none;" class="btn btn-secondary btn-small" href="#">
                    <i class="fas fa-download"></i> CSV exportieren
                </a>
            </div>
            <div class="card-body" style="padding: 0;">
                <div id="voucherContent">
                    <div class="empty-state">
                        <i class="fas fa-ticket-alt"></i>
                        <p>Bitte wählen Sie eine Site aus, um die Vouchers zu laden.</p>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <script>
        const csrfToken = '<?= $auth->getCsrfToken() ?>';
        let currentSiteId = null;
        let allVouchers = [];
        let currentFilter = 'all';
        let currentPage = 1;
        const PAGE_SIZE = 50;

        // Toast Notification anzeigen
        function showToast(type, title, message) {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}-circle"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()">×</button>
            `;

            document.getElementById('toastContainer').appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Vouchers laden (aus DB, optional mit vorherigem Live-Sync)
        async function loadVouchers(syncFirst = false) {
            const select = document.getElementById('siteSelect');
            const siteId = select.value;
            const refreshBtn = document.getElementById('refreshBtn');

            if (!siteId) {
                document.getElementById('voucherContent').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-ticket-alt"></i>
                        <p>Bitte wählen Sie eine Site aus, um die Vouchers zu laden.</p>
                    </div>
                `;
                document.getElementById('statsContainer').style.display = 'none';
                refreshBtn.disabled = true;
                return;
            }

            currentSiteId = siteId;
            refreshBtn.disabled = false;

            refreshBtn.classList.add('loading-state');
            refreshBtn.innerHTML = syncFirst
                ? '<i class="fas fa-spinner fa-spin"></i> Synchronisiere...'
                : '<i class="fas fa-spinner fa-spin"></i> Lade...';

            const loadingText = syncFirst
                ? 'Synchronisiere mit UniFi Controller...'
                : 'Lade Vouchers...';
            document.getElementById('voucherContent').innerHTML = `
                <div class="loading">
                    <i class="fas fa-spinner"></i>
                    <span>${loadingText}</span>
                </div>
            `;

            try {
                const syncParam = syncFirst ? '&sync=1' : '';
                const response = await fetch(`vouchers.php?ajax_get_vouchers=1&site_id=${siteId}${syncParam}`);
                const result = await response.json();

                if (result.success) {
                    allVouchers = result.vouchers;
                    currentPage = 1;
                    document.getElementById('voucherListTitle').textContent = `Vouchers - ${result.site_name} (${result.count})`;
                    updateStats();
                    renderVouchers();
                    document.getElementById('statsContainer').style.display = 'block';

                    // CSV-Button aktualisieren
                    const csvBtn = document.getElementById('csvExportBtn');
                    csvBtn.style.display = 'inline-flex';
                    csvBtn.href = `vouchers.php?export_csv=1&site_id=${siteId}&token=${csrfToken}`;

                    // Sync-Info anzeigen
                    if (result.last_sync) {
                        showToast('success', syncFirst ? 'Synchronisiert' : 'Geladen',
                            `${result.count} Vouchers - Letzte Sync: ${result.last_sync}`);
                    }
                } else {
                    document.getElementById('voucherContent').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-circle" style="color: #dc3545;"></i>
                            <p>${result.message}</p>
                        </div>
                    `;
                    document.getElementById('statsContainer').style.display = 'none';
                }
            } catch (error) {
                document.getElementById('voucherContent').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle" style="color: #dc3545;"></i>
                        <p>Verbindungsfehler: ${error.message}</p>
                    </div>
                `;
                document.getElementById('statsContainer').style.display = 'none';
            }

            // Button zurücksetzen
            refreshBtn.classList.remove('loading-state');
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Aktualisieren';
        }

        // Statistiken aktualisieren
        function updateStats() {
            const valid = allVouchers.filter(v => v.status === 'valid').length;
            const used = allVouchers.filter(v => v.status === 'used').length;
            const expired = allVouchers.filter(v => v.status === 'expired').length;

            document.getElementById('statTotal').textContent = allVouchers.length;
            document.getElementById('statValid').textContent = valid;
            document.getElementById('statUsed').textContent = used;
            document.getElementById('statExpired').textContent = expired;
        }

        // Filter setzen
        function setFilter(filter) {
            currentFilter = filter;
            currentPage = 1;
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.filter === filter);
            });
            renderVouchers();
        }

        function setPage(page) {
            currentPage = page;
            renderVouchers();
            document.querySelector('.card:last-of-type')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Vouchers rendern
        function renderVouchers() {
            let vouchers = allVouchers;

            if (currentFilter !== 'all') {
                vouchers = allVouchers.filter(v => v.status === currentFilter);
            }

            const totalPages = Math.ceil(vouchers.length / PAGE_SIZE);
            if (currentPage > totalPages && totalPages > 0) currentPage = totalPages;
            const pageStart = (currentPage - 1) * PAGE_SIZE;
            const pageVouchers = vouchers.slice(pageStart, pageStart + PAGE_SIZE);

            if (vouchers.length === 0) {
                document.getElementById('voucherContent').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-ticket-alt"></i>
                        <p>Keine Vouchers gefunden${currentFilter !== 'all' ? ' für diesen Filter' : ''}.</p>
                    </div>
                `;
                return;
            }

            let html = `
                <div style="padding: 15px 25px; border-bottom: 1px solid #e0e0e0;">
                    <div class="filter-row">
                        <button class="filter-btn ${currentFilter === 'all' ? 'active' : ''}" data-filter="all" onclick="setFilter('all')">
                            Alle (${allVouchers.length})
                        </button>
                        <button class="filter-btn ${currentFilter === 'valid' ? 'active' : ''}" data-filter="valid" onclick="setFilter('valid')">
                            Gültig (${allVouchers.filter(v => v.status === 'valid').length})
                        </button>
                        <button class="filter-btn ${currentFilter === 'used' ? 'active' : ''}" data-filter="used" onclick="setFilter('used')">
                            Verwendet (${allVouchers.filter(v => v.status === 'used').length})
                        </button>
                        <button class="filter-btn ${currentFilter === 'expired' ? 'active' : ''}" data-filter="expired" onclick="setFilter('expired')">
                            Abgelaufen (${allVouchers.filter(v => v.status === 'expired').length})
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Erstellt</th>
                                <th>Code</th>
                                <th>Notiz</th>
                                <th>Status</th>
                                <th>Nutzung</th>
                                <th>Gültigkeit</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            pageVouchers.forEach(voucher => {
                const createDate = new Date(voucher.create_time * 1000);
                const expireDate = new Date(voucher.expire_time * 1000);
                const now = new Date();

                let statusBadge = '';
                if (voucher.status === 'valid') {
                    statusBadge = '<span class="badge badge-success"><i class="fas fa-check"></i> Gültig</span>';
                } else if (voucher.status === 'used') {
                    statusBadge = '<span class="badge badge-warning"><i class="fas fa-user-check"></i> Verwendet</span>';
                } else {
                    statusBadge = '<span class="badge badge-danger"><i class="fas fa-times"></i> Abgelaufen</span>';
                }

                const usagePercent = voucher.quota > 0 ? Math.min(100, (voucher.used / voucher.quota) * 100) : 0;

                // Verbleibende Zeit berechnen
                let remainingTime = '';
                if (voucher.status === 'valid') {
                    const diff = voucher.expire_time - Math.floor(Date.now() / 1000);
                    if (diff > 0) {
                        const hours = Math.floor(diff / 3600);
                        const minutes = Math.floor((diff % 3600) / 60);
                        if (hours > 0) {
                            remainingTime = `${hours}h ${minutes}m`;
                        } else {
                            remainingTime = `${minutes}m`;
                        }
                    }
                }

                html += `
                    <tr id="voucher-${voucher._id}">
                        <td>
                            <strong>${createDate.toLocaleDateString('de-DE')}</strong><br>
                            <small style="color: #999;">${createDate.toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'})} Uhr</small>
                        </td>
                        <td><code>${voucher.formatted_code}</code></td>
                        <td class="voucher-note" title="${escapeHtml(voucher.note || '-')}">${escapeHtml(voucher.note || '-')}</td>
                        <td>${statusBadge}</td>
                        <td>
                            <div class="usage-info">
                                <span>${voucher.used}/${voucher.quota > 0 ? voucher.quota : '∞'}</span>
                                ${voucher.quota > 0 ? `
                                    <div class="usage-bar">
                                        <div class="usage-bar-fill" style="width: ${usagePercent}%"></div>
                                    </div>
                                ` : ''}
                            </div>
                        </td>
                        <td>
                            ${voucher.status === 'valid' ? `
                                <span style="color: #28a745;">
                                    <i class="fas fa-clock"></i> ${remainingTime}
                                </span><br>
                                <small style="color: #999;">${voucher.duration} Min. gesamt</small>
                            ` : `
                                <small style="color: #999;">${voucher.duration} Min. gesamt</small>
                            `}
                        </td>
                        <td>
                            <button onclick="deleteVoucher('${voucher._id}')"
                                    class="btn btn-danger btn-small"
                                    title="Voucher löschen">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table></div>`;

            // Paginierung
            if (totalPages > 1) {
                html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:15px 25px;border-top:1px solid #e0e0e0;">`;
                html += `<span style="font-size:13px;color:#666;">Seite ${currentPage} von ${totalPages} (${vouchers.length} Einträge)</span>`;
                html += `<div style="display:flex;gap:6px;">`;
                html += `<button class="btn btn-secondary btn-small" onclick="setPage(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;
                for (let p = Math.max(1, currentPage - 2); p <= Math.min(totalPages, currentPage + 2); p++) {
                    html += `<button class="btn btn-small ${p === currentPage ? 'btn-primary' : 'btn-secondary'}" onclick="setPage(${p})">${p}</button>`;
                }
                html += `<button class="btn btn-secondary btn-small" onclick="setPage(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
                html += `</div></div>`;
            }

            document.getElementById('voucherContent').innerHTML = html;
        }

        // HTML escapen
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // AJAX Voucher löschen
        async function deleteVoucher(voucherId) {
            if (!confirm('Voucher wirklich löschen? Der Code wird im UniFi Controller deaktiviert!')) {
                return;
            }

            const row = document.getElementById(`voucher-${voucherId}`);
            if (row) {
                row.classList.add('deleting');
            }

            try {
                const formData = new FormData();
                formData.append('ajax_delete', '1');
                formData.append('voucher_id', voucherId);
                formData.append('site_id', currentSiteId);
                formData.append('csrf_token', csrfToken);

                const response = await fetch('vouchers.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Aus lokalem Array entfernen
                    allVouchers = allVouchers.filter(v => v._id !== voucherId);
                    updateStats();
                    renderVouchers();
                    showToast('success', 'Erfolgreich gelöscht', result.message);
                } else {
                    if (row) {
                        row.classList.remove('deleting');
                    }
                    showToast('error', 'Fehler beim Löschen', result.message);
                }
            } catch (error) {
                if (row) {
                    row.classList.remove('deleting');
                }
                showToast('error', 'Fehler', 'Ein unerwarteter Fehler ist aufgetreten');
                console.error('Error:', error);
            }
        }
    </script>
</body>
</html>
