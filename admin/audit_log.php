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

$db       = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

$filterAction = trim($_GET['action'] ?? '');
$filterUser   = trim($_GET['user_id'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

$where  = [];
$params = [];
if ($filterAction !== '') { $where[] = 'a.action = ?'; $params[] = $filterAction; }
if ($filterUser   !== '') { $where[] = 'a.user_id = ?'; $params[] = (int)$filterUser; }
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total  = (int)$db->fetchOne("SELECT COUNT(*) as c FROM audit_log a $whereStr", $params)['c'];
$pages  = max(1, (int)ceil($total / $perPage));
$logs   = $db->fetchAll(
    "SELECT a.*, u.name as user_name, u.email as user_email
     FROM audit_log a LEFT JOIN users u ON a.user_id = u.id
     $whereStr ORDER BY a.created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// Distinct actions for filter
$actions = $db->fetchAll("SELECT DISTINCT action FROM audit_log ORDER BY action");

// User list for filter
$users = $db->fetchAll("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name");

$currentPage = 'audit_log';
$adminBase   = '';

// WICHTIG: Die Keys muessen den tatsaechlich via writeAuditLog() geschriebenen
// Action-Namen entsprechen (user_create, site_edit, ...), sonst erscheinen
// die Eintraege als rohe Keys.
$actionLabels = [
    'voucher_create'   => '🎫 Voucher erstellt',
    'voucher_bulk'     => '🎫 Bulk Voucher',
    'user_login'       => '🔐 Login',
    'user_create'      => '👤 Benutzer erstellt',
    'user_edit'        => '👤 Benutzer geändert',
    'user_delete'      => '👤 Benutzer gelöscht',
    'site_create'      => '🌐 Site hinzugefügt',
    'site_edit'        => '🌐 Site geändert',
    'site_delete'      => '🌐 Site gelöscht',
    'password_reset'   => '🔑 Passwort-Reset',
    'update_installed' => '🔄 Update installiert',
    'update_failed'    => '🔄 Update fehlgeschlagen',
    'migrations_run'   => '🗄️ Migrationen ausgeführt',
];
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('audit_title') ?> - <?= htmlspecialchars($appTitle) ?></title>
    <?php include __DIR__ . '/../includes/admin_nav.php'; ?>
    <style>
        .page-header { margin-bottom: 25px; }
        .page-title  { font-size: 28px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px; }
        .card { background: var(--bg-card); border-radius: 15px; box-shadow: 0 2px 10px var(--shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 20px; }
        .card-header { padding: 18px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .card-title  { font-size: 16px; font-weight: 600; color: var(--text-primary); }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 11px 15px; background: var(--bg-table-head); color: var(--text-secondary); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .table td { padding: 12px 15px; border-bottom: 1px solid var(--border-color); font-size: 13px; color: var(--text-primary); }
        .table tr:last-child td { border-bottom: none; }
        .table tr:hover td { background: var(--bg-hover); }
        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .filter-bar select { padding: 9px 12px; border: 2px solid var(--border-color); border-radius: 8px; font-size: 13px; background: var(--bg-input); color: var(--text-primary); }
        .filter-bar select:focus { outline: none; border-color: var(--accent); }
        .btn-primary  { background: var(--accent); color: white; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        .ip-cell { font-family: monospace; font-size: 12px; color: var(--text-muted); }
        .details-cell { max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-secondary); }
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 25px; border-top: 1px solid var(--border-color); flex-wrap: wrap; gap: 10px; }
        .page-info { font-size: 13px; color: var(--text-muted); }
        .page-btns  { display: flex; gap: 5px; flex-wrap: wrap; }
        .page-btn   { padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-secondary); cursor: pointer; font-size: 13px; text-decoration: none; transition: all 0.2s; }
        .page-btn:hover { border-color: var(--accent); color: var(--accent); }
        .page-btn.active { background: var(--accent); color: white; border-color: var(--accent); }
        .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 42px; margin-bottom: 15px; display: block; opacity: 0.3; }
        .action-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; background: var(--bg-hover); color: var(--text-secondary); }
    </style>
</head>

    <div class="page-header">
        <h1 class="page-title"><?= __('audit_title') ?></h1>
        <p style="color: var(--text-muted); font-size: 14px;"><?= __('audit_subtitle') ?></p>
    </div>

    <!-- Filter -->
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header"><span class="card-title"><i class="fas fa-filter"></i> <?= __('audit_filter') ?></span></div>
        <div style="padding: 20px 25px;">
            <form method="get" class="filter-bar">
                <div>
                    <label style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:5px;"><?= __('audit_action') ?></label>
                    <select name="action">
                        <option value=""><?= __('audit_filter_all') ?></option>
                        <?php foreach ($actions as $a): ?>
                            <option value="<?= htmlspecialchars($a['action']) ?>" <?= $filterAction === $a['action'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($actionLabels[$a['action']] ?? $a['action']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:5px;"><?= __('audit_user') ?></label>
                    <select name="user_id">
                        <option value="">Alle Benutzer</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= (string)$filterUser === (string)$u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-small"><i class="fas fa-search"></i> Filtern</button>
                <a href="audit_log.php" class="btn btn-secondary btn-small"><i class="fas fa-times"></i> Zurücksetzen</a>
            </form>
        </div>
    </div>

    <!-- Log-Tabelle -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-history"></i> <?= __('audit_title') ?></span>
            <span style="font-size:13px;color:var(--text-muted);"><?= number_format($total) ?> Einträge</span>
        </div>
        <?php if (empty($logs)): ?>
            <div class="empty-state"><i class="fas fa-history"></i><p><?= __('audit_none') ?></p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= __('audit_time') ?></th>
                        <th><?= __('audit_action') ?></th>
                        <th><?= __('audit_user') ?></th>
                        <th><?= __('audit_entity') ?></th>
                        <th><?= __('audit_details') ?></th>
                        <th><?= __('audit_ip') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="white-space:nowrap;color:var(--text-muted);">
                            <?= date('d.m.Y', strtotime($log['created_at'])) ?><br>
                            <small><?= date('H:i:s', strtotime($log['created_at'])) ?></small>
                        </td>
                        <td>
                            <span class="action-chip">
                                <?= htmlspecialchars($actionLabels[$log['action']] ?? $log['action']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($log['user_name']): ?>
                                <strong style="font-size:13px;"><?= htmlspecialchars($log['user_name']) ?></strong><br>
                                <small style="color:var(--text-muted);"><?= htmlspecialchars($log['user_email'] ?? '') ?></small>
                            <?php else: ?>
                                <em style="color:var(--text-muted);">System/Anonym</em>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--text-secondary);">
                            <?php if ($log['entity_type']): ?>
                                <code style="font-size:11px;"><?= htmlspecialchars($log['entity_type']) ?>:<?= htmlspecialchars($log['entity_id'] ?? '') ?></code>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="details-cell" title="<?= htmlspecialchars($log['details'] ?? '') ?>">
                            <?= htmlspecialchars(mb_strimwidth($log['details'] ?? '-', 0, 80, '…')) ?>
                        </td>
                        <td class="ip-cell"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
        <div class="pagination">
            <span class="page-info">Seite <?= $page ?> von <?= $pages ?> (<?= $total ?> Einträge)</span>
            <div class="page-btns">
                <?php
                $baseUrl = '?' . http_build_query(array_filter(['action' => $filterAction, 'user_id' => $filterUser]));
                if ($page > 1): ?>
                    <a href="<?= $baseUrl ?>&page=<?= $page - 1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a>
                <?php endif;
                for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                    <a href="<?= $baseUrl ?>&page=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor;
                if ($page < $pages): ?>
                    <a href="<?= $baseUrl ?>&page=<?= $page + 1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

</div><!-- main-content -->
<script src="../assets/global.js"></script>
</body>
</html>
