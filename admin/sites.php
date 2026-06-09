<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/UniFiController.php';
require_once __DIR__ . '/../includes/I18n.php';
require_once __DIR__ . '/../includes/Helpers.php';

$auth = new Auth();
$auth->requireAdmin();
$db = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
I18n::init();

$error   = '';
$success = '';

// AJAX: Verbindungstest mit gespeicherten Zugangsdaten (Health-Check pro Site)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_test_site'])) {
    header('Content-Type: application/json');
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => __('error_csrf')]);
        exit;
    }
    $site = $db->fetchOne("SELECT * FROM sites WHERE id=?", [(int)$_POST['ajax_test_site']]);
    if (!$site) {
        echo json_encode(['success' => false, 'message' => __('error_site_not_found')]);
        exit;
    }
    $test = UniFiController::testConnection(
        $site['unifi_controller_url'],
        $site['unifi_username'],
        Crypto::decrypt($site['unifi_password']),
        $site['site_id']
    );
    echo json_encode([
        'success' => $test === true,
        'message' => $test === true ? __('site_test_ok') : __('site_test_fail') . ': ' . $test,
    ]);
    exit;
}

// Edit site
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_site'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token']??'')) {
        $error = __('error_csrf');
    } else {
        try {
            $siteId        = (int)$_POST['site_id'];
            $name          = trim($_POST['name']);
            $siteIdStr     = trim($_POST['site_id_str']);
            $controllerUrl = trim($_POST['controller_url']);
            $username      = trim($_POST['username']);
            $password      = $_POST['password'];
            $publicAccess  = isset($_POST['public_access']) ? 1 : 0;
            if (empty($name)||empty($siteIdStr)||empty($controllerUrl)||empty($username)) throw new Exception(__('error_fill_all'));
            if (!empty($password)) {
                $test = UniFiController::testConnection($controllerUrl,$username,$password,$siteIdStr);
                if ($test !== true) throw new Exception(__('site_test_fail').': '.$test);
                $db->execute("UPDATE sites SET name=?,site_id=?,unifi_controller_url=?,unifi_username=?,unifi_password=?,public_access=? WHERE id=?",
                    [$name,$siteIdStr,$controllerUrl,$username,Crypto::encrypt($password),$publicAccess,$siteId]);
            } else {
                // Auch ohne Passwortaenderung testen (mit gespeichertem Passwort) –
                // sonst fallen Tippfehler in URL/Username erst beim naechsten Voucher auf.
                $stored = $db->fetchOne("SELECT unifi_password FROM sites WHERE id=?", [$siteId]);
                if (!$stored) throw new Exception(__('error_site_not_found'));
                $test = UniFiController::testConnection($controllerUrl,$username,Crypto::decrypt($stored['unifi_password']),$siteIdStr);
                if ($test !== true) throw new Exception(__('site_test_fail').': '.$test);
                $db->execute("UPDATE sites SET name=?,site_id=?,unifi_controller_url=?,unifi_username=?,public_access=? WHERE id=?",
                    [$name,$siteIdStr,$controllerUrl,$username,$publicAccess,$siteId]);
            }
            $auth->writeAuditLog($_SESSION['user_id'],'site_edit','site',$siteId,"Site {$name} aktualisiert");
            flashSet(__('sites_updated'));
            header('Location: sites.php');
            exit;
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}

// Add site
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_site'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token']??'')) {
        $error = __('error_csrf');
    } else {
        try {
            $name          = trim($_POST['name']);
            $siteId        = trim($_POST['site_id']);
            $controllerUrl = trim($_POST['controller_url']);
            $username      = trim($_POST['username']);
            $password      = $_POST['password'];
            $publicAccess  = isset($_POST['public_access']) ? 1 : 0;
            if (empty($name)||empty($siteId)||empty($controllerUrl)||empty($username)) throw new Exception(__('error_fill_all'));
            $test = UniFiController::testConnection($controllerUrl,$username,$password,$siteId);
            if ($test !== true) throw new Exception(__('site_test_fail').': '.$test);
            $newId = $db->execute("INSERT INTO sites (name,site_id,unifi_controller_url,unifi_username,unifi_password,public_access) VALUES (?,?,?,?,?,?)",
                [$name,$siteId,$controllerUrl,$username,Crypto::encrypt($password),$publicAccess]);
            $auth->writeAuditLog($_SESSION['user_id'],'site_create','site',$newId,"Site {$name} erstellt");
            flashSet(__('sites_added'));
            header('Location: sites.php');
            exit;
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}

// Delete site (POST + PRG)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_site'])) {
    if ($auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $delId = (int)$_POST['delete_site'];
        $db->query("DELETE FROM sites WHERE id=?", [$delId]);
        $auth->writeAuditLog($_SESSION['user_id'],'site_delete','site',$delId,'Site gelöscht');
        flashSet(__('sites_deleted'));
        header('Location: sites.php');
        exit;
    } else { $error = __('error_csrf'); }
}

// Toggle site (POST + PRG)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_site'])) {
    if ($auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $site = $db->fetchOne("SELECT is_active FROM sites WHERE id=?", [(int)$_POST['toggle_site']]);
        if ($site) {
            $db->query("UPDATE sites SET is_active=? WHERE id=?", [$site['is_active']?0:1,(int)$_POST['toggle_site']]);
            flashSet(__('sites_status_updated'));
            header('Location: sites.php');
            exit;
        }
    } else { $error = __('error_csrf'); }
}

if (empty($success) && empty($error) && ($flash = flashGet())) {
    $success = $flash['message'];
}

$sites = $db->fetchAll("SELECT * FROM sites ORDER BY name");
$currentPage = 'sites';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('sites_title') ?> – <?= htmlspecialchars($appTitle) ?></title>
<?php include __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
    .page-title { font-size: 26px; font-weight: 700; color: var(--text-primary); }
    .sites-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 18px; }
    .site-card { background: var(--bg-card); border: 2px solid var(--border-color); border-radius: 14px; padding: 20px; transition: border-color .2s, box-shadow .2s; }
    .site-card:hover { border-color: var(--accent); box-shadow: 0 4px 14px rgba(102,126,234,.15); }
    .site-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
    .site-name { font-size: 17px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
    .site-id-label { font-size: 12px; color: var(--text-muted); font-family: monospace; }
    .site-info { margin: 14px 0; font-size: 13px; color: var(--text-secondary); }
    .site-info-item { display: flex; align-items: center; gap: 8px; margin-bottom: 7px; }
    .site-actions { display: flex; gap: 7px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border-color); flex-wrap: wrap; }
    .btn { padding: 8px 15px; border-radius: 8px; border: none; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: all .2s; font-size: 13px; }
    .btn-primary { background: var(--accent); color: white; }
    .btn-primary:hover { background: var(--accent-hover); }
    .btn-secondary { background: var(--bg-hover); color: var(--text-secondary); border: 1px solid var(--border-color); }
    .btn-secondary:hover { background: var(--border-color); }
    .btn-danger { background: var(--danger); color: white; }
    .btn-success { background: var(--success); color: white; }
    .btn-sm { padding: 6px 11px; font-size: 12px; }
    .modal { display: none; position: fixed; inset: 0; background: var(--modal-overlay); z-index: 1000; align-items: center; justify-content: center; }
    .modal.active { display: flex; }
    .modal-content { background: var(--bg-card); border-radius: 14px; max-width: 580px; width: 90%; max-height: 90vh; overflow-y: auto; border: 1px solid var(--border-color); }
    .modal-header { padding: 22px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 19px; font-weight: 600; color: var(--text-primary); }
    .modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-muted); }
    .modal-body { padding: 22px 25px; }
    .form-group { margin-bottom: 17px; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    label { display: block; margin-bottom: 7px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
    input[type="text"], input[type="password"], input[type="url"] { width: 100%; padding: 11px; border: 2px solid var(--border-color); border-radius: 8px; font-size: 14px; background: var(--bg-input); color: var(--text-primary); transition: border-color .2s; }
    input:focus { outline: none; border-color: var(--accent); }
    .checkbox-group { display: flex; align-items: center; gap: 10px; }
    .checkbox-group input { width: auto; }
    .empty-card { background: var(--bg-card); border-radius: 14px; border: 1px solid var(--border-color); padding: 60px 20px; text-align: center; color: var(--text-muted); }
    @media(max-width:768px){ .main-content{ margin-left:0!important; } .form-grid{ grid-template-columns:1fr; } }
</style>

<div class="page-header">
    <h1 class="page-title"><?= __('sites_title') ?></h1>
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fas fa-plus"></i> <?= __('sites_add') ?>
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if (empty($sites)): ?>
<div class="empty-card">
    <i class="fas fa-map-marker-alt" style="font-size:48px;margin-bottom:20px;opacity:.3;display:block;"></i>
    <p><?= __('sites_none') ?></p>
</div>
<?php else: ?>
<div class="sites-grid">
    <?php foreach ($sites as $site): ?>
    <div class="site-card">
        <div class="site-card-header">
            <div>
                <div class="site-name"><?= htmlspecialchars($site['name']) ?></div>
                <div class="site-id-label">ID: <?= htmlspecialchars($site['site_id']) ?></div>
            </div>
            <div>
                <?php if ($site['is_active']): ?>
                    <span class="badge badge-success"><i class="fas fa-check"></i> <?= __('status_active') ?></span>
                <?php else: ?>
                    <span class="badge badge-warning"><i class="fas fa-pause"></i> <?= __('status_inactive') ?></span>
                <?php endif; ?>
                <?php if ($site['public_access']): ?>
                    <span class="badge badge-info"><i class="fas fa-globe"></i> <?= __('status_public') ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="site-info">
            <div class="site-info-item">
                <i class="fas fa-server" style="color:var(--accent);width:16px;"></i>
                <span style="word-break:break-all;"><?= htmlspecialchars($site['unifi_controller_url']) ?></span>
            </div>
            <div class="site-info-item">
                <i class="fas fa-user" style="color:var(--accent);width:16px;"></i>
                <span><?= htmlspecialchars($site['unifi_username']) ?></span>
            </div>
            <div class="site-info-item">
                <i class="fas fa-clock" style="color:var(--text-muted);width:16px;"></i>
                <span style="color:var(--text-muted);"><?= date('d.m.Y', strtotime($site['created_at'])) ?></span>
            </div>
        </div>
        <div class="site-actions">
            <button onclick="openEditModal(<?= $site['id'] ?>, '<?= htmlspecialchars($site['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($site['site_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($site['unifi_controller_url'], ENT_QUOTES) ?>', '<?= htmlspecialchars($site['unifi_username'], ENT_QUOTES) ?>', <?= $site['public_access'] ?>)"
                    class="btn btn-secondary btn-sm">
                <i class="fas fa-edit"></i> <?= __('btn_edit') ?>
            </button>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="toggle_site" value="<?= $site['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm">
                    <i class="fas fa-<?= $site['is_active'] ? 'pause' : 'play' ?>"></i>
                    <?= $site['is_active'] ? __('sites_deactivate') : __('sites_activate') ?>
                </button>
            </form>
            <button type="button" class="btn btn-secondary btn-sm" onclick="testSite(<?= $site['id'] ?>, this)"
                    title="<?= __('site_test_btn') ?>" aria-label="<?= __('site_test_btn') ?>">
                <i class="fas fa-plug"></i>
            </button>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('<?= addslashes(__('confirm_delete_site')) ?>')">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="delete_site" value="<?= $site['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                        title="<?= __('btn_delete') ?>" aria-label="<?= __('btn_delete') ?>">
                    <i class="fas fa-trash"></i>
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /main-content -->

<!-- Add Site Modal -->
<div id="addSiteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><?= __('sites_add_title') ?></h2>
            <button class="modal-close" onclick="closeModal('addSiteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="addSiteForm">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="add_site" value="1">
                <div class="form-group">
                    <label><?= __('sites_name') ?></label>
                    <input type="text" name="name" required placeholder="z.B. Hauptgebäude">
                </div>
                <div class="form-group">
                    <label><?= __('sites_site_id') ?></label>
                    <input type="text" name="site_id" required placeholder="z.B. default">
                    <small style="color:var(--text-muted);font-size:12px;">Zu finden in der UniFi Controller URL</small>
                </div>
                <div class="form-group">
                    <label><?= __('sites_controller') ?></label>
                    <input type="url" name="controller_url" required placeholder="https://unifi.example.com:11443">
                    <small style="color:var(--text-muted);font-size:12px;">Vollständige URL inkl. Port</small>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label><?= __('sites_username') ?></label>
                        <input type="text" name="username" required placeholder="admin">
                    </div>
                    <div class="form-group">
                        <label><?= __('sites_password') ?></label>
                        <input type="password" name="password" required>
                    </div>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="add_public" name="public_access">
                    <label for="add_public" style="margin:0;"><?= __('sites_public') ?></label>
                </div>
                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;" id="addSiteSubmitBtn">
                        <i class="fas fa-save"></i> <?= __('sites_add') ?>
                    </button>
                    <button type="button" onclick="closeModal('addSiteModal')" class="btn btn-secondary"><?= __('btn_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Site Modal -->
<div id="editSiteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><?= __('sites_edit_title') ?></h2>
            <button class="modal-close" onclick="closeModal('editSiteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="editSiteForm">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="edit_site" value="1">
                <input type="hidden" name="site_id" id="edit_site_id">
                <div class="form-group">
                    <label><?= __('sites_name') ?></label>
                    <input type="text" id="edit_name" name="name" required>
                </div>
                <div class="form-group">
                    <label><?= __('sites_site_id') ?></label>
                    <input type="text" id="edit_site_id_str" name="site_id_str" required>
                </div>
                <div class="form-group">
                    <label><?= __('sites_controller') ?></label>
                    <input type="url" id="edit_controller_url" name="controller_url" required>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label><?= __('sites_username') ?></label>
                        <input type="text" id="edit_username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label><?= __('sites_password_edit') ?></label>
                        <input type="password" id="edit_password" name="password" placeholder="Leer lassen = nicht ändern">
                        <small style="color:var(--text-muted);font-size:12px;"><?= __('sites_password_hint') ?></small>
                    </div>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="edit_public_access" name="public_access">
                    <label for="edit_public_access" style="margin:0;"><?= __('sites_public') ?></label>
                </div>
                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;" id="editSiteSubmitBtn">
                        <i class="fas fa-save"></i> <?= __('btn_save') ?>
                    </button>
                    <button type="button" onclick="closeModal('editSiteModal')" class="btn btn-secondary"><?= __('btn_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="toast-container"></div>
<script src="../assets/global.js"></script>
<script>
function openModal() { document.getElementById('addSiteModal').classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openEditModal(id, name, siteIdStr, controllerUrl, username, publicAccess) {
    document.getElementById('edit_site_id').value         = id;
    document.getElementById('edit_name').value            = name;
    document.getElementById('edit_site_id_str').value     = siteIdStr;
    document.getElementById('edit_controller_url').value  = controllerUrl;
    document.getElementById('edit_username').value        = username;
    document.getElementById('edit_password').value        = '';
    document.getElementById('edit_public_access').checked = publicAccess == 1;
    document.getElementById('editSiteModal').classList.add('active');
}

document.getElementById('addSiteForm').addEventListener('submit', function() {
    const btn = document.getElementById('addSiteSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?= addslashes(__('sites_testing')) ?>';
});
document.getElementById('editSiteForm').addEventListener('submit', function() {
    const btn = document.getElementById('editSiteSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?= addslashes(__('sites_testing')) ?>';
});

['addSiteModal','editSiteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});

async function testSite(siteId, btn) {
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const fd = new FormData();
        fd.append('ajax_test_site', siteId);
        fd.append('csrf_token', '<?= $auth->getCsrfToken() ?>');
        const result = await fetch('sites.php', { method: 'POST', body: fd }).then(r => r.json());
        showToast(result.success ? 'success' : 'error', '<?= addslashes(__('site_test_btn')) ?>', result.message);
    } catch (e) {
        showToast('error', '<?= addslashes(__('site_test_btn')) ?>', e.message);
    }
    btn.disabled = false;
    btn.innerHTML = original;
}
</script>
</body>
</html>
