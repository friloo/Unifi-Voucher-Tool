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

// AJAX: Statistiken abrufen (immer aus DB, optional vorher Live-Sync)
if (isset($_GET['ajax_stats'])) {
    header('Content-Type: application/json');

    $syncFirst = isset($_GET['sync']) && $_GET['sync'] == '1';

    try {
        $sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1");
        $siteData = [];
        $totalStats = [
            'total' => 0,
            'valid' => 0,
            'used' => 0,
            'expired' => 0
        ];
        $syncErrors = [];

        // Bei sync=1: Erst alle Sites live synchronisieren
        if ($syncFirst) {
            foreach ($sites as $site) {
                try {
                    $controller = new UniFiController(
                        $site['unifi_controller_url'],
                        $site['unifi_username'],
                        $site['unifi_password'],
                        $site['site_id']
                    );
                    $controller->syncVouchersToDatabase($db, $site['id']);
                } catch (Exception $e) {
                    $syncErrors[$site['id']] = $e->getMessage();
                }
            }

            // Last sync time aktualisieren
            $db->execute(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('last_cron_sync', NOW())
                 ON DUPLICATE KEY UPDATE setting_value = NOW()"
            );
        }

        // Immer aus Datenbank abrufen
        foreach ($sites as $site) {
            $siteStats = $db->fetchOne(
                "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) as valid,
                    SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as used,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
                 FROM vouchers WHERE site_id = ?",
                [$site['id']]
            );

            $siteData[] = [
                'site_id' => $site['id'],
                'site_name' => $site['name'],
                'stats' => [
                    'total' => (int)($siteStats['total'] ?? 0),
                    'valid' => (int)($siteStats['valid'] ?? 0),
                    'used' => (int)($siteStats['used'] ?? 0),
                    'expired' => (int)($siteStats['expired'] ?? 0)
                ],
                'error' => $syncErrors[$site['id']] ?? null
            ];

            $totalStats['total'] += (int)($siteStats['total'] ?? 0);
            $totalStats['valid'] += (int)($siteStats['valid'] ?? 0);
            $totalStats['used'] += (int)($siteStats['used'] ?? 0);
            $totalStats['expired'] += (int)($siteStats['expired'] ?? 0);
        }

        $lastSync = $db->getSetting('last_cron_sync', '');

        echo json_encode([
            'success' => true,
            'synced' => $syncFirst,
            'sites' => $siteData,
            'total' => $totalStats,
            'last_sync' => $lastSync ? date('d.m.Y H:i:s', strtotime($lastSync)) : null,
            'timestamp' => date('H:i:s')
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Basis-Statistiken (aus Datenbank für initiale Anzeige)
$stats = [
    'total_sites' => $db->fetchOne("SELECT COUNT(*) as count FROM sites WHERE is_active = 1")['count'],
    'total_users' => $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE is_active = 1")['count'],
    'total_vouchers_today' => $db->fetchOne("SELECT COUNT(*) as count FROM vouchers WHERE DATE(created_at) = CURDATE()")['count'],
    'total_vouchers_month' => $db->fetchOne("SELECT COUNT(*) as count FROM vouchers WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")['count'],
];

// Sites für Live-Anzeige mit gecachten Statistiken
$sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1");

// Voucher-Statistiken aus DB (gecached durch Cron)
$voucherStats = $db->fetchOne(
    "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) as valid,
        SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as used,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
     FROM vouchers"
);

// Pro-Site-Statistiken aus DB
$siteStats = [];
foreach ($sites as $site) {
    $siteStat = $db->fetchOne(
        "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) as valid,
            SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as used,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
         FROM vouchers WHERE site_id = ?",
        [$site['id']]
    );
    $siteStats[$site['id']] = $siteStat;
}

// Letzte Synchronisation
$lastCronSync = $db->getSetting('last_cron_sync', '');

// Vouchers für Diagramm (letzte 7 Tage)
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $count = $db->fetchOne(
        "SELECT COUNT(*) as count FROM vouchers WHERE DATE(created_at) = ?",
        [$date]
    )['count'];

    $chartData[] = [
        'date' => date('d.m', strtotime($date)),
        'count' => $count
    ];
}

// Top 5 Benutzer (meiste Vouchers)
$topUsers = $db->fetchAll(
    "SELECT u.name, u.email, COUNT(v.id) as voucher_count
     FROM users u
     LEFT JOIN vouchers v ON u.id = v.user_id
     WHERE v.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY u.id
     ORDER BY voucher_count DESC
     LIMIT 5"
);

// Letzte Vouchers (aus DB)
$recentVouchers = $db->fetchAll(
    "SELECT v.*, s.name as site_name, u.name as user_name
     FROM vouchers v
     LEFT JOIN sites s ON v.site_id = s.id
     LEFT JOIN users u ON v.user_id = u.id
     ORDER BY v.created_at DESC
     LIMIT 10"
);

$currentUser = $auth->getCurrentUser();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - <?= htmlspecialchars($appTitle) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .header-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
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
        .sidebar-nav {
            list-style: none;
        }
        .sidebar-nav li {
            margin-bottom: 5px;
        }
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
        .sidebar-nav i {
            width: 20px;
            text-align: center;
        }
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: calc(100vh - 70px);
        }
        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        .page-subtitle {
            color: #666;
            font-size: 14px;
        }
        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #d4edda;
            color: #155724;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        .live-badge .dot {
            width: 8px;
            height: 8px;
            background: #28a745;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        .live-badge.loading {
            background: #fff3cd;
            color: #856404;
        }
        .live-badge.loading .dot {
            background: #ffc107;
        }
        .live-badge.error {
            background: #f8d7da;
            color: #721c24;
        }
        .live-badge.error .dot {
            background: #dc3545;
            animation: none;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        .stat-card.live {
            border-color: #28a745;
            border-width: 2px;
        }
        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .stat-card-title {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }
        .stat-card-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .stat-card-value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
        }
        .stat-card-value.valid { color: #28a745; }
        .stat-card-value.used { color: #ffc107; }
        .stat-card-value.expired { color: #dc3545; }
        .stat-card-sub {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        .card-body {
            padding: 25px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th {
            text-align: left;
            padding: 12px;
            background: #f8f9fa;
            color: #666;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table td {
            padding: 15px 12px;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
        }
        .table tr:last-child td {
            border-bottom: none;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
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
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #f8f9fa;
            color: #666;
            border: 1px solid #e0e0e0;
        }
        .btn-secondary:hover {
            background: #e9ecef;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
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
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        .site-status {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .site-status-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
        }
        .site-status-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        .site-status-card.loading {
            opacity: 0.7;
        }
        .site-status-card.error {
            border-color: #dc3545;
            background: #fff5f5;
        }
        .site-status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .site-status-name {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        .site-status-badge {
            display: flex;
            gap: 8px;
        }
        .site-voucher-count {
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .site-voucher-count.zero {
            background: #ccc;
        }
        .site-status-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 10px;
        }
        .site-stat {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .site-stat-value {
            font-size: 20px;
            font-weight: 700;
        }
        .site-stat-value.valid { color: #28a745; }
        .site-stat-value.used { color: #ffc107; }
        .site-stat-value.expired { color: #dc3545; }
        .site-stat-label {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        .site-error {
            color: #dc3545;
            font-size: 13px;
            margin-top: 10px;
        }
        .top-users-list {
            list-style: none;
            padding: 0;
        }
        .top-users-list li {
            display: flex;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        .top-users-list li:last-child {
            border-bottom: none;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .user-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        .user-count {
            background: #f0f0f0;
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: 600;
            color: #333;
        }
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #666;
        }
        .last-update {
            color: #999;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="header-title">
                <i class="fas fa-shield-alt"></i> Administration
            </div>
        </div>
        <div class="header-right">
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Zurück zur Startseite
            </a>
            <div class="user-menu">
                <div class="user-avatar"><?= strtoupper(substr($currentUser['name'], 0, 1)) ?></div>
                <div>
                    <div style="font-weight: 500; font-size: 14px;"><?= htmlspecialchars($currentUser['name']) ?></div>
                    <div style="font-size: 12px; color: #999;">Administrator</div>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar">
        <nav class="sidebar-nav">
            <ul>
                <li><a href="index.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="sites.php"><i class="fas fa-map-marker-alt"></i> Sites verwalten</a></li>
                <li><a href="users.php"><i class="fas fa-users"></i> Benutzer verwalten</a></li>
                <li><a href="vouchers.php"><i class="fas fa-ticket-alt"></i> Live Vouchers</a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> Einstellungen</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle">Übersicht über Ihr UniFi Voucher System</p>
            </div>
            <div class="refresh-indicator">
                <span id="liveBadge" class="live-badge">
                    <span class="dot"></span>
                    <span id="liveStatus">Daten aus DB</span>
                </span>
                <button onclick="refreshData('live')" class="btn btn-success btn-small" id="refreshBtn" title="Live vom Controller abrufen">
                    <i class="fas fa-sync-alt"></i> Live aktualisieren
                </button>
            </div>
        </div>

        <!-- Live Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-title">Aktive Sites</div>
                    <div class="stat-card-icon" style="background: #e3f2fd; color: #1976d2;">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?= $stats['total_sites'] ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-title">Benutzer</div>
                    <div class="stat-card-icon" style="background: #f3e5f5; color: #7b1fa2;">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?= $stats['total_users'] ?></div>
            </div>

            <div class="stat-card live">
                <div class="stat-card-header">
                    <div class="stat-card-title">🟢 Gültige Vouchers</div>
                    <div class="stat-card-icon" style="background: #e8f5e9; color: #388e3c;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card-value valid" id="liveValid"><?= (int)($voucherStats['valid'] ?? 0) ?></div>
                <div class="stat-card-sub" id="subValid">Letzte Sync: <?= $lastCronSync ? date('H:i', strtotime($lastCronSync)) : 'nie' ?></div>
            </div>

            <div class="stat-card live">
                <div class="stat-card-header">
                    <div class="stat-card-title">🟡 Verwendet</div>
                    <div class="stat-card-icon" style="background: #fff3e0; color: #f57c00;">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
                <div class="stat-card-value used" id="liveUsed"><?= (int)($voucherStats['used'] ?? 0) ?></div>
                <div class="stat-card-sub">Quota ausgeschöpft</div>
            </div>

            <div class="stat-card live">
                <div class="stat-card-header">
                    <div class="stat-card-title">🔴 Abgelaufen</div>
                    <div class="stat-card-icon" style="background: #ffebee; color: #d32f2f;">
                        <i class="fas fa-times-circle"></i>
                    </div>
                </div>
                <div class="stat-card-value expired" id="liveExpired"><?= (int)($voucherStats['expired'] ?? 0) ?></div>
                <div class="stat-card-sub">Im Controller</div>
            </div>

            <div class="stat-card live">
                <div class="stat-card-header">
                    <div class="stat-card-title">📊 Gesamt</div>
                    <div class="stat-card-icon" style="background: #e0e0e0; color: #616161;">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                </div>
                <div class="stat-card-value" id="liveTotal"><?= (int)($voucherStats['total'] ?? 0) ?></div>
                <div class="stat-card-sub">Alle Sites</div>
            </div>
        </div>

        <!-- Aktive Vouchers pro Site - LIVE -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🔴 Live: Vouchers pro Site</h2>
                <span class="last-update" id="lastUpdate">Letzte Sync: <?= $lastCronSync ? date('d.m.Y H:i:s', strtotime($lastCronSync)) : 'noch nie' ?></span>
            </div>
            <div class="card-body" style="padding: 20px;">
                <div class="site-status" id="siteStatusContainer">
                    <?php foreach ($sites as $site):
                        $ss = $siteStats[$site['id']] ?? ['total' => 0, 'valid' => 0, 'used' => 0, 'expired' => 0];
                    ?>
                    <div class="site-status-card" id="site-<?= $site['id'] ?>">
                        <div class="site-status-header">
                            <div class="site-status-name"><?= htmlspecialchars($site['name']) ?></div>
                            <div class="site-status-badge">
                                <span class="site-voucher-count <?= (int)$ss['total'] === 0 ? 'zero' : '' ?>" id="site-total-<?= $site['id'] ?>">
                                    <?= (int)$ss['total'] ?>
                                </span>
                            </div>
                        </div>
                        <div class="site-status-stats">
                            <div class="site-stat">
                                <div class="site-stat-value valid" id="site-valid-<?= $site['id'] ?>"><?= (int)$ss['valid'] ?></div>
                                <div class="site-stat-label">Gültig</div>
                            </div>
                            <div class="site-stat">
                                <div class="site-stat-value used" id="site-used-<?= $site['id'] ?>"><?= (int)$ss['used'] ?></div>
                                <div class="site-stat-label">Verwendet</div>
                            </div>
                            <div class="site-stat">
                                <div class="site-stat-value expired" id="site-expired-<?= $site['id'] ?>"><?= (int)$ss['expired'] ?></div>
                                <div class="site-stat-label">Abgelaufen</div>
                            </div>
                        </div>
                        <div class="site-error" id="site-error-<?= $site['id'] ?>" style="display: none;"></div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($sites)): ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #999;">
                        <i class="fas fa-map-marker-alt" style="font-size: 32px; margin-bottom: 15px; opacity: 0.3;"></i>
                        <p>Keine aktiven Sites konfiguriert.</p>
                        <a href="sites.php" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i> Site hinzufügen
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Voucher-Trend (letzte 7 Tage) -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📊 Voucher-Trend (Letzte 7 Tage)</h2>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="voucherChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Top 5 Benutzer -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🏆 Top 5 Benutzer (Letzte 30 Tage)</h2>
            </div>
            <div class="card-body">
                <?php if (empty($topUsers)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>Noch keine Daten verfügbar</p>
                    </div>
                <?php else: ?>
                    <ul class="top-users-list">
                        <?php foreach ($topUsers as $index => $user): ?>
                            <li>
                                <div class="user-info">
                                    <div class="user-avatar-small"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
                                    <div>
                                        <div style="font-weight: 600; color: #333;"><?= htmlspecialchars($user['name']) ?></div>
                                        <div style="font-size: 12px; color: #999;"><?= htmlspecialchars($user['email']) ?></div>
                                    </div>
                                </div>
                                <div class="user-count"><?= $user['voucher_count'] ?> Vouchers</div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Letzte Vouchers -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Letzte Vouchers (aus Datenbank)</h2>
                <a href="vouchers.php" class="btn btn-secondary">
                    <i class="fas fa-external-link-alt"></i> Live-Ansicht
                </a>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($recentVouchers)): ?>
                    <div class="empty-state">
                        <i class="fas fa-ticket-alt"></i>
                        <p>Noch keine Vouchers erstellt</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Erstellt</th>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Site</th>
                                <th>Status</th>
                                <th>Ersteller</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentVouchers as $voucher): ?>
                            <tr>
                                <td><?= date('d.m.Y H:i', strtotime($voucher['created_at'])) ?></td>
                                <td><code><?= htmlspecialchars($voucher['voucher_code']) ?></code></td>
                                <td><?= htmlspecialchars($voucher['voucher_name']) ?></td>
                                <td><span class="badge badge-info"><?= htmlspecialchars($voucher['site_name']) ?></span></td>
                                <td>
                                    <?php
                                    $status = $voucher['status'] ?? 'valid';
                                    $statusClass = $status === 'valid' ? 'success' : ($status === 'used' ? 'warning' : 'danger');
                                    $statusText = $status === 'valid' ? 'Gültig' : ($status === 'used' ? 'Verwendet' : 'Abgelaufen');
                                    ?>
                                    <span class="badge badge-<?= $statusClass ?>"><?= $statusText ?></span>
                                </td>
                                <td><?= $voucher['user_name'] ? htmlspecialchars($voucher['user_name']) : '<em>Öffentlich</em>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Voucher-Trend Chart
        const ctx = document.getElementById('voucherChart').getContext('2d');
        const chartData = <?= json_encode($chartData) ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.map(d => d.date),
                datasets: [{
                    label: 'Erstellte Vouchers',
                    data: chartData.map(d => d.count),
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Daten aktualisieren
        let isLoading = false;

        async function refreshData(mode = 'db') {
            if (isLoading) return;
            isLoading = true;

            const liveBadge = document.getElementById('liveBadge');
            const liveStatus = document.getElementById('liveStatus');
            const refreshBtn = document.getElementById('refreshBtn');

            liveBadge.className = 'live-badge loading';
            liveStatus.textContent = mode === 'live' ? 'Lade Live-Daten...' : 'Aktualisiere...';
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Laden...';

            try {
                const url = `index.php?ajax_stats=1&mode=${mode}${mode === 'live' ? '&sync=1' : ''}`;
                const response = await fetch(url);
                const result = await response.json();

                if (result.success) {
                    // Gesamt-Statistiken aktualisieren
                    document.getElementById('liveValid').textContent = result.total.valid;
                    document.getElementById('liveUsed').textContent = result.total.used;
                    document.getElementById('liveExpired').textContent = result.total.expired;
                    document.getElementById('liveTotal').textContent = result.total.total;

                    // Sub-Text aktualisieren
                    const subText = mode === 'live' ? `Live: ${result.timestamp}` : `Sync: ${result.last_sync || 'nie'}`;
                    document.getElementById('subValid').textContent = subText;

                    // Pro-Site-Statistiken aktualisieren
                    result.sites.forEach(site => {
                        const card = document.getElementById(`site-${site.site_id}`);
                        const totalBadge = document.getElementById(`site-total-${site.site_id}`);
                        const validEl = document.getElementById(`site-valid-${site.site_id}`);
                        const usedEl = document.getElementById(`site-used-${site.site_id}`);
                        const expiredEl = document.getElementById(`site-expired-${site.site_id}`);
                        const errorEl = document.getElementById(`site-error-${site.site_id}`);

                        if (card) {
                            card.classList.remove('loading', 'error');

                            if (site.error) {
                                card.classList.add('error');
                                totalBadge.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                                totalBadge.className = 'site-voucher-count zero';
                                validEl.textContent = '-';
                                usedEl.textContent = '-';
                                expiredEl.textContent = '-';
                                errorEl.textContent = site.error;
                                errorEl.style.display = 'block';
                            } else {
                                totalBadge.textContent = site.stats.total;
                                totalBadge.className = 'site-voucher-count' + (site.stats.total === 0 ? ' zero' : '');
                                validEl.textContent = site.stats.valid;
                                usedEl.textContent = site.stats.used;
                                expiredEl.textContent = site.stats.expired;
                                errorEl.style.display = 'none';
                            }
                        }
                    });

                    // Zeitstempel aktualisieren
                    if (mode === 'live') {
                        document.getElementById('lastUpdate').textContent = `Live aktualisiert: ${result.timestamp}`;
                    } else if (result.last_sync) {
                        document.getElementById('lastUpdate').textContent = `Letzte Sync: ${result.last_sync}`;
                    }

                    liveBadge.className = 'live-badge';
                    liveStatus.textContent = mode === 'live' ? 'Live aktualisiert' : 'Daten aus DB';
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                liveBadge.className = 'live-badge error';
                liveStatus.textContent = 'Fehler beim Laden';
                console.error('Data error:', error);
            }

            isLoading = false;
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Live aktualisieren';
        }

        // Keine automatische Live-Aktualisierung mehr - Daten kommen aus DB via Cron
    </script>
</body>
</html>
