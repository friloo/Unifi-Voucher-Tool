<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../includes/I18n.php';
require_once __DIR__ . '/../includes/Helpers.php';

$auth = new Auth();
$auth->requireAdmin();
$db = Database::getInstance();
$mailer = new Mailer();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
$smtpEnabled = $db->getSetting('smtp_enabled', '0') === '1';
I18n::init();

$error   = '';
$success = '';

// Send password reset link (POST statt GET: kein CSRF-Token in URLs/Referrern,
// keine versehentliche Ausloesung durch Link-Prefetching)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['send_reset'])) {
    if ($auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $targetUser = $db->fetchOne("SELECT * FROM users WHERE id=? AND is_active=1 AND password_hash IS NOT NULL", [(int)$_POST['send_reset']]);
        if ($targetUser) {
            try {
                $db->execute("DELETE FROM password_reset_tokens WHERE user_id=?", [$targetUser['id']]);
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $db->execute("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?,?,?)", [$targetUser['id'], $token, $expiresAt]);
                $systemUrl = rtrim($db->getSetting('system_url', ''), '/');
                if (empty($systemUrl)) {
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http';
                    $scriptPath = dirname(dirname($_SERVER['SCRIPT_NAME']));
                    $systemUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . ($scriptPath==='/'?'':$scriptPath);
                }
                $resetUrl = $systemUrl . '/reset_password.php?token=' . $token;
                $mailer->sendRaw($targetUser['email'], $appTitle . ' – Passwort zurücksetzen',
                    "Hallo {$targetUser['name']},\n\nEin Administrator hat für Sie einen Passwort-Reset-Link erstellt:\n\n{$resetUrl}\n\n(Gültig für 1 Stunde)\n\n{$appTitle}");
                flashSet(__('reset_link_sent', ['email' => $targetUser['email']]));
                header('Location: users.php');
                exit;
            } catch (Exception $e) {
                $error = 'Fehler beim Senden: ' . $e->getMessage();
            }
        } else {
            $error = __('reset_link_failed');
        }
    } else {
        $error = __('error_csrf');
    }
}

// Edit user
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_user'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token']??'')) {
        $error = __('error_csrf');
    } else {
        try {
            $userId  = (int)$_POST['user_id'];
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $siteIds = $_POST['site_ids'] ?? [];
            // Lockout-Schutz: Der letzte Weg ins Admin-Panel darf nicht
            // versehentlich gekappt werden.
            if ($userId === (int)$_SESSION['user_id'] && !$isAdmin) {
                throw new Exception(__('error_self_demote'));
            }
            $oldUser = $db->fetchOne("SELECT * FROM users WHERE id=?", [$userId]);
            $oldSites= $db->fetchAll("SELECT s.name FROM sites s INNER JOIN user_site_access usa ON s.id=usa.site_id WHERE usa.user_id=?", [$userId]);
            $db->query("UPDATE users SET is_admin=? WHERE id=?", [$isAdmin, $userId]);
            $db->query("DELETE FROM user_site_access WHERE user_id=?", [$userId]);
            $newSites = [];
            if (!$isAdmin && !empty($siteIds)) {
                foreach ($siteIds as $siteId) {
                    $db->execute("INSERT INTO user_site_access (user_id, site_id) VALUES (?,?)", [$userId, $siteId]);
                    $site = $db->fetchOne("SELECT name FROM sites WHERE id=?", [$siteId]);
                    if ($site) $newSites[] = $site['name'];
                }
            }
            $changes = [];
            if ($oldUser['is_admin'] != $isAdmin) {
                $changes[] = $isAdmin ? 'Sie wurden zum Administrator ernannt' : 'Ihre Administrator-Rechte wurden entfernt';
            }
            $oldSiteNames = array_column($oldSites,'name');
            $addedSites   = array_diff($newSites, $oldSiteNames);
            $removedSites = array_diff($oldSiteNames, $newSites);
            if (!empty($addedSites))   $changes[] = 'Zugriff gewährt auf: ' . implode(', ', $addedSites);
            if (!empty($removedSites)) $changes[] = 'Zugriff entfernt von: ' . implode(', ', $removedSites);
            if ($isAdmin && !$oldUser['is_admin']) $changes[] = 'Sie haben nun Zugriff auf alle Sites';
            if (!empty($changes)) $mailer->sendUserNotification($oldUser['email'], $oldUser['name'], $changes);
            $auth->writeAuditLog($_SESSION['user_id'], 'user_edit', 'user', $userId, implode('; ', $changes) ?: 'Keine Änderungen');
            flashSet(__('users_updated') . (!empty($changes) ? ' '.__('users_notified') : ''));
            header('Location: users.php');
            exit;
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}

// Add user
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_user'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token']??'')) {
        $error = __('error_csrf');
    } else {
        try {
            $email   = trim($_POST['email']);
            $name    = trim($_POST['name']);
            $password= $_POST['password'];
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $siteIds = $_POST['site_ids'] ?? [];
            if (empty($email)||empty($name)||empty($password)) throw new Exception(__('error_fill_all'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception(__('error_email_invalid'));
            if (strlen($password) < 8) throw new Exception(__('settings_pw_minlength'));
            if ($db->fetchOne("SELECT id FROM users WHERE email=?", [$email])) throw new Exception(__('error_email_exists'));
            $userId = $auth->registerUser($email, $name, $password, $isAdmin);
            if (!$userId) throw new Exception(__('error_user_create'));
            if (!$isAdmin && !empty($siteIds)) {
                foreach ($siteIds as $siteId) {
                    $db->execute("INSERT INTO user_site_access (user_id, site_id) VALUES (?,?)", [$userId, $siteId]);
                }
            }
            $auth->writeAuditLog($_SESSION['user_id'], 'user_create', 'user', $userId, "Benutzer {$name} erstellt");
            flashSet(__('users_added'));
            header('Location: users.php');
            exit;
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}

// Delete user (POST + PRG)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_user'])) {
    if ($auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $deleteId = (int)$_POST['delete_user'];
        if ($deleteId === (int)$_SESSION['user_id']) {
            $error = __('error_self_delete');
        } else {
            $db->query("DELETE FROM users WHERE id=?", [$deleteId]);
            $auth->writeAuditLog($_SESSION['user_id'], 'user_delete', 'user', $deleteId, 'Benutzer gelöscht');
            flashSet(__('users_deleted'));
            header('Location: users.php');
            exit;
        }
    } else { $error = __('error_csrf'); }
}

// Toggle user active (POST + PRG)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_user'])) {
    if ($auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $toggleId = (int)$_POST['toggle_user'];
        if ($toggleId === (int)$_SESSION['user_id']) {
            $error = __('error_self_deactivate');
        } else {
            $user = $db->fetchOne("SELECT is_active FROM users WHERE id=?", [$toggleId]);
            if ($user) {
                $newStatus = $user['is_active'] ? 0 : 1;
                $db->query("UPDATE users SET is_active=? WHERE id=?", [$newStatus, $toggleId]);
                flashSet(__('users_status_updated'));
                header('Location: users.php');
                exit;
            }
        }
    } else { $error = __('error_csrf'); }
}

// 2FA eines Benutzers zurücksetzen (Admin-Hilfe bei verlorenem Authenticator)
// POST + PRG wie die uebrigen state-aendernden Aktionen
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reset_2fa'])) {
    if ($auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $auth->disableTotp((int)$_POST['reset_2fa']);
        flashSet('2FA des Benutzers wurde zurückgesetzt.');
        header('Location: users.php');
        exit;
    } else { $error = __('error_csrf'); }
}

if (empty($success) && empty($error) && ($flash = flashGet())) {
    $success = $flash['message'];
}

$users   = $db->fetchAll("SELECT * FROM users ORDER BY name");
$sites   = $db->fetchAll("SELECT * FROM sites WHERE is_active=1 ORDER BY name");
$userSiteAccess = [];
foreach ($users as $user) {
    $userSiteAccess[$user['id']] = $db->fetchAll("SELECT s.id, s.name FROM sites s INNER JOIN user_site_access usa ON s.id=usa.site_id WHERE usa.user_id=?", [$user['id']]);
}
$currentPage = 'users';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('users_title') ?> – <?= htmlspecialchars($appTitle) ?></title>
<?php include __DIR__ . '/../includes/admin_nav.php'; ?>
<style>
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
    .page-title { font-size: 26px; font-weight: 700; color: var(--text-primary); }
    .card { background: var(--bg-card); border-radius: 14px; box-shadow: 0 2px 10px var(--shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
    .card-header { padding: 18px 22px; border-bottom: 1px solid var(--border-color); }
    .card-title { font-size: 16px; font-weight: 600; color: var(--text-primary); }
    .table { width: 100%; border-collapse: collapse; }
    .table th { text-align: left; padding: 12px 15px; background: var(--bg-table-head); color: var(--text-muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
    .table td { padding: 13px 15px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); font-size: 14px; }
    .table tr:last-child td { border-bottom: none; }
    .table tr:hover { background: var(--bg-hover); }
    .btn { padding: 8px 16px; border-radius: 8px; border: none; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: all .2s; font-size: 13px; }
    .btn-primary { background: var(--accent); color: white; }
    .btn-primary:hover { background: var(--accent-hover); }
    .btn-secondary { background: var(--bg-hover); color: var(--text-secondary); border: 1px solid var(--border-color); }
    .btn-secondary:hover { background: var(--border-color); }
    .btn-danger { background: var(--danger); color: white; }
    .btn-warning { background: var(--warning); color: #333; }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    .modal { display: none; position: fixed; inset: 0; background: var(--modal-overlay); z-index: 1000; align-items: center; justify-content: center; }
    .modal.active { display: flex; }
    .modal-content { background: var(--bg-card); border-radius: 14px; max-width: 580px; width: 90%; max-height: 90vh; overflow-y: auto; border: 1px solid var(--border-color); }
    .modal-header { padding: 22px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 19px; font-weight: 600; color: var(--text-primary); }
    .modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-muted); }
    .modal-body { padding: 22px 25px; }
    .form-group { margin-bottom: 18px; }
    label { display: block; margin-bottom: 7px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
    input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 11px; border: 2px solid var(--border-color); border-radius: 8px; font-size: 14px; background: var(--bg-input); color: var(--text-primary); transition: border-color .2s; }
    input:focus { outline: none; border-color: var(--accent); }
    .checkbox-group { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .checkbox-group input { width: auto; }
    .site-selection { border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; max-height: 180px; overflow-y: auto; background: var(--bg-hover); }
    .empty-state { text-align: center; padding: 50px 20px; color: var(--text-muted); }
    .empty-state i { font-size: 40px; margin-bottom: 15px; opacity: .3; display: block; }
    .action-btns { display: flex; gap: 5px; flex-wrap: wrap; }
    @media(max-width:768px){ .main-content{ margin-left:0!important; } .table th:nth-child(5),.table td:nth-child(5){ display:none; } }
</style>

<div class="page-header">
    <h1 class="page-title"><?= __('users_title') ?></h1>
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fas fa-plus"></i> <?= __('users_add') ?>
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= __('users_all') ?></h2></div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($users)): ?>
            <div class="empty-state"><i class="fas fa-users"></i><p><?= __('users_none_found') ?></p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th><?= __('label_name') ?></th>
                    <th><?= __('label_email') ?></th>
                    <th><?= __('label_role') ?></th>
                    <th><?= __('label_status') ?></th>
                    <th><?= __('users_site_access') ?></th>
                    <th><?= __('users_last_login') ?></th>
                    <th><?= __('label_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <?php $userSiteIds = array_column($userSiteAccess[$user['id']]??[], 'id'); ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($user['name']) ?></strong>
                        <?php if ($user['id'] == $_SESSION['user_id']): ?>
                            <span class="badge badge-info"><?= __('users_you') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($user['email']) ?></td>
                    <td>
                        <?php if ($user['is_admin']): ?>
                            <span class="badge badge-danger"><i class="fas fa-crown"></i> <?= __('status_admin') ?></span>
                        <?php else: ?>
                            <span class="badge badge-info"><?= __('status_user') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user['is_active']): ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> <?= __('status_active') ?></span>
                        <?php else: ?>
                            <span class="badge badge-warning"><i class="fas fa-pause"></i> <?= __('status_inactive') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user['is_admin']): ?>
                            <em style="color:var(--text-muted);"><?= __('users_all_sites') ?></em>
                        <?php elseif (!empty($userSiteAccess[$user['id']])): ?>
                            <?php foreach ($userSiteAccess[$user['id']] as $s): ?>
                                <span class="badge badge-info"><?= htmlspecialchars($s['name']) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <em style="color:var(--text-muted);"><?= __('users_none') ?></em>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px;">
                        <?php if ($user['last_login']): ?>
                            <?= date('d.m.Y H:i', strtotime($user['last_login'])) ?>
                        <?php else: ?>
                            <em style="color:var(--text-muted);"><?= __('users_never') ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-btns">
                            <button onclick="openEditModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name'], ENT_QUOTES) ?>', <?= $user['is_admin'] ?>, [<?= implode(',', array_map('intval', $userSiteIds)) ?>])"
                                    class="btn btn-secondary btn-sm" title="<?= __('btn_edit') ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                                    <input type="hidden" name="toggle_user" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"
                                            title="<?= $user['is_active'] ? __('sites_deactivate') : __('sites_activate') ?>"
                                            aria-label="<?= $user['is_active'] ? __('sites_deactivate') : __('sites_activate') ?>">
                                        <i class="fas fa-<?= $user['is_active'] ? 'pause' : 'play' ?>"></i>
                                    </button>
                                </form>
                                <?php if ($smtpEnabled && !empty($user['password_hash'])): ?>
                                <form method="post" style="display:inline;"
                                      onsubmit="return confirm('<?= addslashes(__('confirm_send_reset', ['email' => $user['email']])) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                                    <input type="hidden" name="send_reset" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm"
                                            title="<?= __('users_reset_pw') ?>" aria-label="<?= __('users_reset_pw') ?>">
                                        <i class="fas fa-key"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (!empty($user['totp_enabled'])): ?>
                                <form method="post" style="display:inline;"
                                      onsubmit="return confirm('2FA für <?= htmlspecialchars($user['email'], ENT_QUOTES) ?> zurücksetzen?')">
                                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                                    <input type="hidden" name="reset_2fa" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"
                                            title="2FA zurücksetzen" aria-label="2FA zurücksetzen">
                                        <i class="fas fa-user-shield"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="post" style="display:inline;"
                                      onsubmit="return confirm('<?= addslashes(__('confirm_delete_user')) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                                    <input type="hidden" name="delete_user" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            title="<?= __('btn_delete') ?>" aria-label="<?= __('btn_delete') ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /main-content -->

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><?= __('users_add_title') ?></h2>
            <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="addUserForm">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <div class="form-group">
                    <label><?= __('label_name') ?> *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label><?= __('label_email') ?> *</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label><?= __('label_password') ?> *</label>
                    <input type="password" name="password" required minlength="8">
                    <small style="color:var(--text-muted);font-size:12px;"><?= __('users_password_hint') ?></small>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="add_is_admin" name="is_admin" onchange="toggleSiteSelection('add')">
                        <label for="add_is_admin" style="margin:0;"><?= __('users_admin_check') ?></label>
                    </div>
                    <small style="color:var(--text-muted);font-size:12px;"><?= __('users_admin_hint') ?></small>
                </div>
                <div class="form-group" id="siteSelectionGroup">
                    <label><?= __('users_site_access') ?></label>
                    <div class="site-selection">
                        <?php if (empty($sites)): ?>
                            <em style="color:var(--text-muted);"><?= __('users_no_sites') ?></em>
                        <?php else: ?>
                            <?php foreach ($sites as $site): ?>
                            <div class="checkbox-group">
                                <input type="checkbox" name="site_ids[]" value="<?= $site['id'] ?>" id="add_site_<?= $site['id'] ?>">
                                <label for="add_site_<?= $site['id'] ?>" style="margin:0;"><?= htmlspecialchars($site['name']) ?></label>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <small style="color:var(--text-muted);font-size:12px;"><?= __('users_site_hint') ?></small>
                </div>
                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="submit" name="add_user" class="btn btn-primary" style="flex:1;">
                        <i class="fas fa-save"></i> <?= __('users_save') ?>
                    </button>
                    <button type="button" onclick="closeModal('addUserModal')" class="btn btn-secondary"><?= __('btn_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><?= __('users_edit_title') ?></h2>
            <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="editUserForm">
                <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group">
                    <label><?= __('label_name') ?></label>
                    <input type="text" id="edit_name" readonly style="background:var(--bg-hover);">
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="edit_is_admin" name="is_admin" onchange="toggleSiteSelection('edit')">
                        <label for="edit_is_admin" style="margin:0;"><?= __('users_admin_check') ?></label>
                    </div>
                    <small style="color:var(--text-muted);font-size:12px;"><?= __('users_admin_hint') ?></small>
                </div>
                <div class="form-group" id="editSiteSelectionGroup">
                    <label><?= __('users_site_access') ?></label>
                    <div class="site-selection" id="editSitesList">
                        <?php foreach ($sites as $site): ?>
                        <div class="checkbox-group">
                            <input type="checkbox" name="site_ids[]" value="<?= $site['id'] ?>" id="edit_site_<?= $site['id'] ?>">
                            <label for="edit_site_<?= $site['id'] ?>" style="margin:0;"><?= htmlspecialchars($site['name']) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="submit" name="edit_user" class="btn btn-primary" style="flex:1;">
                        <i class="fas fa-save"></i> <?= __('users_save_edit') ?>
                    </button>
                    <button type="button" onclick="closeModal('editUserModal')" class="btn btn-secondary"><?= __('btn_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="toast-container"></div>
<script src="../assets/global.js"></script>
<script>
function openModal() { document.getElementById('addUserModal').classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openEditModal(userId, userName, isAdmin, siteIds) {
    document.getElementById('edit_user_id').value = userId;
    document.getElementById('edit_name').value    = userName;
    document.getElementById('edit_is_admin').checked = isAdmin == 1;
    document.querySelectorAll('#editSitesList input[type="checkbox"]').forEach(cb => cb.checked = false);
    siteIds.forEach(id => { const cb = document.getElementById('edit_site_' + id); if (cb) cb.checked = true; });
    toggleSiteSelection('edit');
    document.getElementById('editUserModal').classList.add('active');
}

function toggleSiteSelection(mode) {
    const isAdmin = document.getElementById(mode + '_is_admin').checked;
    const group   = document.getElementById(mode === 'add' ? 'siteSelectionGroup' : 'editSiteSelectionGroup');
    if (group) group.style.display = isAdmin ? 'none' : 'block';
}

toggleSiteSelection('add');

['addUserModal','editUserModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
</script>
</body>
</html>
