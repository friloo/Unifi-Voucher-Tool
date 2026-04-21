<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Mailer.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();
$mailer = new Mailer();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

$error = '';
$success = '';

// Benutzer bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            $userId = (int)$_POST['user_id'];
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $siteIds = $_POST['site_ids'] ?? [];
            
            // Alten Status abrufen
            $oldUser = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
            $oldIsAdmin = $oldUser['is_admin'];
            $oldSites = $db->fetchAll("SELECT s.name FROM sites s INNER JOIN user_site_access usa ON s.id = usa.site_id WHERE usa.user_id = ?", [$userId]);
            
            // Admin-Status aktualisieren
            $db->query("UPDATE users SET is_admin = ? WHERE id = ?", [$isAdmin, $userId]);
            
            // Alte Site-Zugriffe löschen (nur wenn nicht Admin)
            $db->query("DELETE FROM user_site_access WHERE user_id = ?", [$userId]);
            
            // Neue Site-Zugriffe zuweisen (nur wenn nicht Admin)
            $newSites = [];
            if (!$isAdmin && !empty($siteIds)) {
                foreach ($siteIds as $siteId) {
                    $db->execute(
                        "INSERT INTO user_site_access (user_id, site_id) VALUES (?, ?)",
                        [$userId, $siteId]
                    );
                    $site = $db->fetchOne("SELECT name FROM sites WHERE id = ?", [$siteId]);
                    if ($site) {
                        $newSites[] = $site['name'];
                    }
                }
            }
            
            // E-Mail-Benachrichtigung vorbereiten
            $changes = [];
            
            if ($oldIsAdmin != $isAdmin) {
                if ($isAdmin) {
                    $changes[] = "Sie wurden zum Administrator ernannt";
                } else {
                    $changes[] = "Ihre Administrator-Rechte wurden entfernt";
                }
            }
            
            // Site-Änderungen erkennen
            $oldSiteNames = array_column($oldSites, 'name');
            $addedSites = array_diff($newSites, $oldSiteNames);
            $removedSites = array_diff($oldSiteNames, $newSites);
            
            if (!empty($addedSites)) {
                $changes[] = "Zugriff gewährt auf: " . implode(', ', $addedSites);
            }
            
            if (!empty($removedSites)) {
                $changes[] = "Zugriff entfernt von: " . implode(', ', $removedSites);
            }
            
            if ($isAdmin && !$oldIsAdmin) {
                $changes[] = "Sie haben nun Zugriff auf alle Sites";
            }
            
            // E-Mail senden wenn Änderungen vorliegen
            if (!empty($changes)) {
                $mailer->sendUserNotification($oldUser['email'], $oldUser['name'], $changes);
            }
            
            $success = 'Benutzer erfolgreich aktualisiert!' . (!empty($changes) ? ' Benachrichtigung wurde versendet.' : '');
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Benutzer hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            $email = trim($_POST['email']);
            $name = trim($_POST['name']);
            $password = $_POST['password'];
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $siteIds = $_POST['site_ids'] ?? [];
            
            if (empty($email) || empty($name) || empty($password)) {
                throw new Exception('Bitte füllen Sie alle Pflichtfelder aus');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Ungültige E-Mail-Adresse');
            }
            
            if (strlen($password) < 8) {
                throw new Exception('Passwort muss mindestens 8 Zeichen lang sein');
            }
            
            // Prüfen ob E-Mail bereits existiert
            $existing = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
            if ($existing) {
                throw new Exception('Ein Benutzer mit dieser E-Mail existiert bereits');
            }
            
            // Benutzer anlegen
            $userId = $auth->registerUser($email, $name, $password, $isAdmin);
            
            if (!$userId) {
                throw new Exception('Benutzer konnte nicht erstellt werden');
            }
            
            // Site-Zugriffe zuweisen (nur wenn nicht Admin)
            if (!$isAdmin && !empty($siteIds)) {
                foreach ($siteIds as $siteId) {
                    $db->execute(
                        "INSERT INTO user_site_access (user_id, site_id) VALUES (?, ?)",
                        [$userId, $siteId]
                    );
                }
            }
            
            $success = 'Benutzer erfolgreich erstellt!';
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Benutzer löschen
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if ($auth->validateCsrfToken($_GET['token'])) {
        $deleteId = (int)$_GET['delete'];
        $currentUserId = $_SESSION['user_id'];
        
        if ($deleteId === $currentUserId) {
            $error = 'Sie können sich nicht selbst löschen';
        } else {
            $db->query("DELETE FROM users WHERE id = ?", [$deleteId]);
            $success = 'Benutzer erfolgreich gelöscht!';
        }
    } else {
        $error = 'Ungültiges Sicherheits-Token';
    }
}

// Benutzer aktivieren/deaktivieren
if (isset($_GET['toggle']) && isset($_GET['token'])) {
    if ($auth->validateCsrfToken($_GET['token'])) {
        $toggleId = (int)$_GET['toggle'];
        $currentUserId = $_SESSION['user_id'];
        
        if ($toggleId === $currentUserId) {
            $error = 'Sie können sich nicht selbst deaktivieren';
        } else {
            $user = $db->fetchOne("SELECT is_active FROM users WHERE id = ?", [$toggleId]);
            if ($user) {
                $newStatus = $user['is_active'] ? 0 : 1;
                $db->query("UPDATE users SET is_active = ? WHERE id = ?", [$newStatus, $toggleId]);
                $success = 'Benutzer-Status aktualisiert!';
            }
        }
    }
}

// Alle Benutzer und Sites abrufen
$users = $db->fetchAll("SELECT * FROM users ORDER BY name");
$sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1 ORDER BY name");

// Site-Zugriffe für jeden Benutzer abrufen
$userSiteAccess = [];
foreach ($users as $user) {
    $userSiteAccess[$user['id']] = $db->fetchAll(
        "SELECT s.name FROM sites s 
         INNER JOIN user_site_access usa ON s.id = usa.site_id 
         WHERE usa.user_id = ?",
        [$user['id']]
    );
}

$currentUser = $auth->getCurrentUser();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benutzer verwalten - <?= htmlspecialchars($appTitle) ?></title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .page-title { font-size: 28px; font-weight: 600; color: #333; }
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
        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
        }
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
        }
        .card-title { font-size: 18px; font-weight: 600; color: #333; }
        .card-body { padding: 0; }
        .alert {
            padding: 14px 25px;
            margin: 0 25px 20px 25px;
            border-radius: 10px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
        }
        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #3c3;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th {
            text-align: left;
            padding: 15px;
            background: #f8f9fa;
            color: #666;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table td {
            padding: 15px;
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
            font-size: 11px;
            font-weight: 500;
            margin-right: 5px;
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
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: white;
            border-radius: 15px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-title { font-size: 20px; font-weight: 600; }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        .modal-body { padding: 25px; }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .checkbox-group input {
            width: auto;
        }
        .site-selection {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            max-height: 200px;
            overflow-y: auto;
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
                <li><a href="users.php" class="active"><i class="fas fa-users"></i> Benutzer verwalten</a></li>
                <li><a href="vouchers.php"><i class="fas fa-ticket-alt"></i> Voucher-Historie</a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> Einstellungen</a></li>
            </ul>
        </nav>
    </div>
    
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Benutzer verwalten</h1>
            <button onclick="openModal()" class="btn btn-primary">
                <i class="fas fa-plus"></i> Neuer Benutzer
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
                <h2 class="card-title">Alle Benutzer</h2>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>Noch keine Benutzer vorhanden</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>E-Mail</th>
                                <th>Rolle</th>
                                <th>Status</th>
                                <th>Site-Zugriffe</th>
                                <th>Letzter Login</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($user['name']) ?></strong>
                                    <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                        <span class="badge badge-info">Sie</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td>
                                    <?php if ($user['is_admin']): ?>
                                        <span class="badge badge-danger"><i class="fas fa-crown"></i> Admin</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">Benutzer</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['is_active']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><i class="fas fa-pause"></i> Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['is_admin']): ?>
                                        <em style="color: #999;">Alle Sites</em>
                                    <?php elseif (!empty($userSiteAccess[$user['id']])): ?>
                                        <?php foreach ($userSiteAccess[$user['id']] as $site): ?>
                                            <span class="badge badge-info"><?= htmlspecialchars($site['name']) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <em style="color: #999;">Keine</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['last_login']): ?>
                                        <?= date('d.m.Y H:i', strtotime($user['last_login'])) ?>
                                    <?php else: ?>
                                        <em style="color: #999;">Noch nie</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <button onclick="openEditModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name'], ENT_QUOTES) ?>', <?= $user['is_admin'] ?>, [<?= implode(',', array_map(function($s) { return $s['site_id'] ?? 0; }, $db->fetchAll("SELECT site_id FROM user_site_access WHERE user_id = ?", [$user['id']]))) ?>])" 
                                           class="btn btn-secondary btn-small">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?toggle=<?= $user['id'] ?>&token=<?= $auth->getCsrfToken() ?>" 
                                           class="btn btn-secondary btn-small">
                                            <i class="fas fa-<?= $user['is_active'] ? 'pause' : 'play' ?>"></i>
                                        </a>
                                        <a href="?delete=<?= $user['id'] ?>&token=<?= $auth->getCsrfToken() ?>" 
                                           class="btn btn-danger btn-small"
                                           onclick="return confirm('Benutzer wirklich löschen?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <button onclick="openEditModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name'], ENT_QUOTES) ?>', <?= $user['is_admin'] ?>, [<?= implode(',', array_map(function($s) { return $s['site_id'] ?? 0; }, $db->fetchAll("SELECT site_id FROM user_site_access WHERE user_id = ?", [$user['id']]))) ?>])" 
                                           class="btn btn-secondary btn-small">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal für neuen Benutzer -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Neuen Benutzer anlegen</h2>
                <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" id="addUserForm">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    
                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">E-Mail *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Passwort *</label>
                        <input type="password" id="password" name="password" required minlength="8">
                        <small style="color: #999; font-size: 12px;">Mindestens 8 Zeichen</small>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="is_admin" name="is_admin" onchange="toggleSiteSelection('add')">
                            <label for="is_admin" style="margin: 0;">Administrator-Rechte</label>
                        </div>
                        <small style="color: #999; font-size: 12px;">Admins haben Zugriff auf alle Sites und Einstellungen</small>
                    </div>
                    
                    <div class="form-group" id="siteSelectionGroup">
                        <label>Site-Zugriffe</label>
                        <div class="site-selection">
                            <?php if (empty($sites)): ?>
                                <em style="color: #999;">Keine Sites verfügbar. Bitte zuerst Sites anlegen.</em>
                            <?php else: ?>
                                <?php foreach ($sites as $site): ?>
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="site_ids[]" value="<?= $site['id'] ?>" id="site_<?= $site['id'] ?>">
                                        <label for="site_<?= $site['id'] ?>" style="margin: 0;"><?= htmlspecialchars($site['name']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <small style="color: #999; font-size: 12px;">Wählen Sie die Sites, auf die dieser Benutzer Zugriff haben soll</small>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 25px;">
                        <button type="submit" name="add_user" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Benutzer anlegen
                        </button>
                        <button type="button" onclick="closeModal('addUserModal')" class="btn btn-secondary">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal für Benutzer bearbeiten -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Benutzer bearbeiten</h2>
                <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" id="editUserForm">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" id="edit_name" readonly style="background: #f5f5f5;">
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="edit_is_admin" name="is_admin" onchange="toggleSiteSelection('edit')">
                            <label for="edit_is_admin" style="margin: 0;">Administrator-Rechte</label>
                        </div>
                        <small style="color: #999; font-size: 12px;">Admins haben Zugriff auf alle Sites und Einstellungen</small>
                    </div>
                    
                    <div class="form-group" id="editSiteSelectionGroup">
                        <label>Site-Zugriffe</label>
                        <div class="site-selection" id="editSitesList">
                            <?php if (!empty($sites)): ?>
                                <?php foreach ($sites as $site): ?>
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="site_ids[]" value="<?= $site['id'] ?>" id="edit_site_<?= $site['id'] ?>">
                                        <label for="edit_site_<?= $site['id'] ?>" style="margin: 0;"><?= htmlspecialchars($site['name']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 25px;">
                        <button type="submit" name="edit_user" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Änderungen speichern
                        </button>
                        <button type="button" onclick="closeModal('editUserModal')" class="btn btn-secondary">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openModal() {
            document.getElementById('addUserModal').classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function openEditModal(userId, userName, isAdmin, siteIds) {
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_name').value = userName;
            document.getElementById('edit_is_admin').checked = isAdmin == 1;
            
            // Alle Checkboxen erst deaktivieren
            document.querySelectorAll('#editSitesList input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            
            // Ausgewählte Sites aktivieren
            siteIds.forEach(siteId => {
                const checkbox = document.getElementById('edit_site_' + siteId);
                if (checkbox) checkbox.checked = true;
            });
            
            toggleSiteSelection('edit');
            document.getElementById('editUserModal').classList.add('active');
        }
        
        function toggleSiteSelection(mode) {
            const isAdmin = document.getElementById(mode + '_is_admin').checked;
            const siteSelection = document.getElementById(mode === 'add' ? 'siteSelectionGroup' : 'editSiteSelectionGroup');
            siteSelection.style.display = isAdmin ? 'none' : 'block';
        }
        
        // Initial state
        toggleSiteSelection('add');
        
        // Modal schließen bei Klick außerhalb
        document.getElementById('addUserModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal('addUserModal');
            }
        });
        
        document.getElementById('editUserModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal('editUserModal');
            }
        });
    </script>
</body>
</html>