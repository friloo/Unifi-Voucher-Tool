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

// Profil hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_template'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        try {
            $name        = trim($_POST['name'] ?? '');
            $maxUses     = (int)($_POST['max_uses'] ?? 1);
            $expireMin   = (int)($_POST['expire_minutes'] ?? 480);
            $description = trim($_POST['description'] ?? '');

            $qosDown  = max(0, (int)($_POST['qos_rate_max_down'] ?? 0)) ?: null;
            $qosUp    = max(0, (int)($_POST['qos_rate_max_up'] ?? 0)) ?: null;
            $qosQuota = max(0, (int)($_POST['qos_usage_quota'] ?? 0)) ?: null;

            if (empty($name)) throw new Exception(__('error_name_req'));
            if ($maxUses < 1)  $maxUses = 1;
            if ($expireMin < 1) $expireMin = 60;

            $db->execute(
                "INSERT INTO voucher_templates (name, max_uses, expire_minutes, description, qos_rate_max_down, qos_rate_max_up, qos_usage_quota, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$name, $maxUses, $expireMin, $description, $qosDown, $qosUp, $qosQuota, $_SESSION['user_id']]
            );
            $success = __('templates_added');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Profil bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_template'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        try {
            $id          = (int)$_POST['template_id'];
            $name        = trim($_POST['name'] ?? '');
            $maxUses     = (int)($_POST['max_uses'] ?? 1);
            $expireMin   = (int)($_POST['expire_minutes'] ?? 480);
            $description = trim($_POST['description'] ?? '');
            $isActive    = isset($_POST['is_active']) ? 1 : 0;

            $qosDown  = max(0, (int)($_POST['qos_rate_max_down'] ?? 0)) ?: null;
            $qosUp    = max(0, (int)($_POST['qos_rate_max_up'] ?? 0)) ?: null;
            $qosQuota = max(0, (int)($_POST['qos_usage_quota'] ?? 0)) ?: null;

            if (empty($name)) throw new Exception(__('error_name_req'));

            $db->execute(
                "UPDATE voucher_templates SET name=?, max_uses=?, expire_minutes=?, description=?, qos_rate_max_down=?, qos_rate_max_up=?, qos_usage_quota=?, is_active=? WHERE id=?",
                [$name, $maxUses, $expireMin, $description, $qosDown, $qosUp, $qosQuota, $isActive, $id]
            );
            $success = __('templates_updated');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Profil löschen
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if ($auth->validateCsrfToken($_GET['token'])) {
        $db->execute("DELETE FROM voucher_templates WHERE id = ?", [(int)$_GET['delete']]);
        $success = __('templates_deleted');
    } else {
        $error = __('error_csrf');
    }
}

$templates = $db->fetchAll("SELECT t.*, u.name as creator FROM voucher_templates t LEFT JOIN users u ON t.created_by = u.id ORDER BY t.is_active DESC, t.name");

$currentPage = 'templates';
$adminBase   = '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('templates_title') ?> - <?= htmlspecialchars($appTitle) ?></title>
    <?php include __DIR__ . '/../includes/admin_nav.php'; ?>
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-title  { font-size: 28px; font-weight: 600; color: var(--text-primary); }
        .alert { padding: 14px 20px; border-radius: 10px; margin-bottom: 25px; font-size: 14px; }
        .alert-error   { background: #fee; border: 1px solid #fcc; color: #c33; }
        .alert-success { background: #efe; border: 1px solid #cfc; color: #3c3; }
        .card { background: var(--bg-card); border-radius: 15px; box-shadow: 0 2px 10px var(--shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 25px; }
        .card-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .card-title  { font-size: 18px; font-weight: 600; color: var(--text-primary); }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 15px; background: var(--bg-table-head); color: var(--text-secondary); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .table td { padding: 14px 15px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); font-size: 14px; }
        .table tr:last-child td { border-bottom: none; }
        .table tr:hover td { background: var(--bg-hover); }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 500; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-secondary { background: var(--bg-hover); color: var(--text-muted); }
        .btn-primary  { background: var(--accent); color: white; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-danger   { background: var(--danger); color: white; }
        .btn-small    { padding: 6px 12px; font-size: 12px; }
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: var(--modal-overlay); z-index: 500; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--bg-card); border-radius: 15px; max-width: 520px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .modal-header { padding: 22px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title  { font-size: 18px; font-weight: 600; color: var(--text-primary); }
        .modal-close  { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-muted); }
        .modal-body   { padding: 25px; }
        .form-group   { margin-bottom: 18px; }
        label { display: block; margin-bottom: 7px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
        input[type="text"], input[type="number"], textarea, select { width: 100%; padding: 11px 14px; border: 2px solid var(--border-color); border-radius: 8px; font-size: 14px; background: var(--bg-input); color: var(--text-primary); transition: border-color 0.2s; font-family: inherit; }
        input:focus, textarea:focus { outline: none; border-color: var(--accent); }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input { width: auto; accent-color: var(--accent); }
        .help-text { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 48px; margin-bottom: 20px; opacity: 0.3; display: block; }
        .duration-badge { display: inline-flex; align-items: center; gap: 5px; background: var(--bg-hover); padding: 3px 10px; border-radius: 20px; font-size: 12px; color: var(--text-secondary); }
    </style>
</head>

    <div class="page-header">
        <div>
            <h1 class="page-title"><?= __('templates_title') ?></h1>
            <p style="color: var(--text-muted); font-size: 14px; margin-top: 5px;"><?= __('templates_subtitle') ?></p>
        </div>
        <button onclick="openAddModal()" class="btn btn-primary">
            <i class="fas fa-plus"></i> <?= __('templates_add') ?>
        </button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?= __('templates_title') ?></h2>
            <span style="font-size: 13px; color: var(--text-muted);"><?= count($templates) ?> Profile</span>
        </div>
        <?php if (empty($templates)): ?>
            <div class="empty-state">
                <i class="fas fa-layer-group"></i>
                <p style="font-size: 15px; margin-bottom: 8px;"><?= __('templates_none') ?></p>
                <p style="font-size: 13px;"><?= __('templates_add_hint') ?></p>
                <button onclick="openAddModal()" class="btn btn-primary" style="margin-top: 20px;"><i class="fas fa-plus"></i> <?= __('templates_add') ?></button>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= __('templates_name') ?></th>
                            <th><?= __('templates_devices') ?></th>
                            <th><?= __('templates_duration') ?></th>
                            <th><?= __('templates_desc') ?></th>
                            <th><?= __('label_status') ?></th>
                            <th><?= __('label_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $t): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                            <td>
                                <span class="duration-badge"><i class="fas fa-mobile-alt"></i> <?= (int)$t['max_uses'] ?></span>
                            </td>
                            <td>
                                <?php
                                $m = (int)$t['expire_minutes'];
                                if ($m >= 1440 && $m % 1440 === 0) {
                                    $durLabel = ($m / 1440) . ' Tag' . ($m / 1440 > 1 ? 'e' : '');
                                } elseif ($m >= 60 && $m % 60 === 0) {
                                    $durLabel = ($m / 60) . ' Std.';
                                } else {
                                    $durLabel = $m . ' Min.';
                                }
                                ?>
                                <span class="duration-badge"><i class="fas fa-clock"></i> <?= $durLabel ?></span>
                            </td>
                            <td style="color: var(--text-secondary);"><?= htmlspecialchars($t['description'] ?? '-') ?></td>
                            <td>
                                <?php if ($t['is_active']): ?>
                                    <span class="badge badge-success"><?= __('status_active') ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?= __('status_inactive') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button onclick="openEditModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>', <?= (int)$t['max_uses'] ?>, <?= (int)$t['expire_minutes'] ?>, '<?= htmlspecialchars($t['description'] ?? '', ENT_QUOTES) ?>', <?= (int)$t['is_active'] ?>, <?= (int)($t['qos_rate_max_down'] ?? 0) ?>, <?= (int)($t['qos_rate_max_up'] ?? 0) ?>, <?= (int)($t['qos_usage_quota'] ?? 0) ?>)"
                                        class="btn btn-secondary btn-small"><i class="fas fa-edit"></i></button>
                                <a href="?delete=<?= $t['id'] ?>&token=<?= $auth->getCsrfToken() ?>"
                                   onclick="return confirm('Profil wirklich löschen?')"
                                   class="btn btn-danger btn-small"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div><!-- main-content -->

<!-- Modal: Hinzufügen -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-plus-circle" style="color: var(--accent);"></i> <?= __('templates_add') ?></h2>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <div class="form-group"><label><?= __('templates_name') ?> *</label><input type="text" name="name" required placeholder="z.B. Tagespass, Event 4h"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label><?= __('templates_devices') ?></label>
                        <input type="number" name="max_uses" value="1" min="1" max="100">
                        <div class="help-text">Max. gleichzeitige Geräte</div>
                    </div>
                    <div class="form-group">
                        <label><?= __('templates_duration') ?> *</label>
                        <input type="number" name="expire_minutes" value="480" min="1" max="525600">
                        <div class="help-text">480 = 8 Stunden</div>
                    </div>
                </div>
                <div class="form-group"><label><?= __('templates_desc') ?></label><textarea name="description" rows="2" placeholder="Kurze Beschreibung für Ihr Team"></textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group"><label>Download (kbit/s)</label><input type="number" name="qos_rate_max_down" min="0" placeholder="0 = unbegrenzt"></div>
                    <div class="form-group"><label>Upload (kbit/s)</label><input type="number" name="qos_rate_max_up" min="0" placeholder="0 = unbegrenzt"></div>
                    <div class="form-group"><label>Datenlimit (MB)</label><input type="number" name="qos_usage_quota" min="0" placeholder="0 = unbegrenzt"></div>
                </div>
                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="submit" name="add_template" class="btn btn-primary" style="flex:1;"><i class="fas fa-save"></i> <?= __('btn_save') ?></button>
                    <button type="button" onclick="closeModal('addModal')" class="btn btn-secondary"><?= __('btn_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Bearbeiten -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-edit" style="color: var(--accent);"></i> <?= __('templates_edit') ?></h2>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="template_id" id="editId">
                <div class="form-group"><label><?= __('templates_name') ?> *</label><input type="text" name="name" id="editName" required></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label><?= __('templates_devices') ?></label>
                        <input type="number" name="max_uses" id="editMaxUses" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label><?= __('templates_duration') ?></label>
                        <input type="number" name="expire_minutes" id="editExpireMin" min="1">
                    </div>
                </div>
                <div class="form-group"><label><?= __('templates_desc') ?></label><textarea name="description" id="editDesc" rows="2"></textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group"><label>Download (kbit/s)</label><input type="number" name="qos_rate_max_down" id="editQosDown" min="0" placeholder="0 = unbegrenzt"></div>
                    <div class="form-group"><label>Upload (kbit/s)</label><input type="number" name="qos_rate_max_up" id="editQosUp" min="0" placeholder="0 = unbegrenzt"></div>
                    <div class="form-group"><label>Datenlimit (MB)</label><input type="number" name="qos_usage_quota" id="editQosQuota" min="0" placeholder="0 = unbegrenzt"></div>
                </div>
                <div class="checkbox-group" style="margin-bottom:20px;">
                    <input type="checkbox" name="is_active" id="editActive">
                    <label for="editActive" style="margin:0;"><?= __('status_active') ?></label>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="submit" name="edit_template" class="btn btn-primary" style="flex:1;"><i class="fas fa-save"></i> <?= __('btn_save') ?></button>
                    <button type="button" onclick="closeModal('editModal')" class="btn btn-secondary"><?= __('btn_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/global.js"></script>
<script>
function openAddModal()  { document.getElementById('addModal').classList.add('active'); }
function closeModal(id)  { document.getElementById(id).classList.remove('active'); }
function openEditModal(id, name, maxUses, expMin, desc, isActive, qosDown, qosUp, qosQuota) {
    document.getElementById('editId').value       = id;
    document.getElementById('editName').value     = name;
    document.getElementById('editMaxUses').value  = maxUses;
    document.getElementById('editExpireMin').value = expMin;
    document.getElementById('editDesc').value     = desc;
    document.getElementById('editActive').checked = isActive == 1;
    document.getElementById('editQosDown').value  = qosDown || '';
    document.getElementById('editQosUp').value    = qosUp || '';
    document.getElementById('editQosQuota').value = qosQuota || '';
    document.getElementById('editModal').classList.add('active');
}
['addModal','editModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
</script>
</body>
</html>
