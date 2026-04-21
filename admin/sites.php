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

$error = '';
$success = '';

// Site bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_site'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            $siteId = (int)$_POST['site_id'];
            $name = trim($_POST['name']);
            $siteIdStr = trim($_POST['site_id_str']);
            $controllerUrl = trim($_POST['controller_url']);
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $publicAccess = isset($_POST['public_access']) ? 1 : 0;
            
            if (empty($name) || empty($siteIdStr) || empty($controllerUrl) || empty($username)) {
                throw new Exception('Bitte füllen Sie alle Pflichtfelder aus');
            }
            
            // Wenn neues Passwort, Verbindung testen
            if (!empty($password)) {
                $testResult = UniFiController::testConnection($controllerUrl, $username, $password, $siteIdStr);
                if ($testResult !== true) {
                    throw new Exception('Verbindung fehlgeschlagen: ' . $testResult);
                }
                
                // Mit neuem Passwort aktualisieren
                $db->execute(
                    "UPDATE sites SET name = ?, site_id = ?, unifi_controller_url = ?, unifi_username = ?, unifi_password = ?, public_access = ? WHERE id = ?",
                    [$name, $siteIdStr, $controllerUrl, $username, $password, $publicAccess, $siteId]
                );
            } else {
                // Ohne Passwort-Änderung
                $db->execute(
                    "UPDATE sites SET name = ?, site_id = ?, unifi_controller_url = ?, unifi_username = ?, public_access = ? WHERE id = ?",
                    [$name, $siteIdStr, $controllerUrl, $username, $publicAccess, $siteId]
                );
            }
            
            $success = 'Site erfolgreich aktualisiert!';
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Site hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_site'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            $name = trim($_POST['name']);
            $siteId = trim($_POST['site_id']);
            $controllerUrl = trim($_POST['controller_url']);
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $publicAccess = isset($_POST['public_access']) ? 1 : 0;
            
            if (empty($name) || empty($siteId) || empty($controllerUrl) || empty($username)) {
                throw new Exception('Bitte füllen Sie alle Pflichtfelder aus');
            }
            
            // Verbindung testen
            $testResult = UniFiController::testConnection($controllerUrl, $username, $password, $siteId);
            if ($testResult !== true) {
                throw new Exception('Verbindung fehlgeschlagen: ' . $testResult);
            }
            
            $db->execute(
                "INSERT INTO sites (name, site_id, unifi_controller_url, unifi_username, unifi_password, public_access) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$name, $siteId, $controllerUrl, $username, $password, $publicAccess]
            );
            
            $success = 'Site erfolgreich hinzugefügt!';
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Site löschen
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if ($auth->validateCsrfToken($_GET['token'])) {
        $db->query("DELETE FROM sites WHERE id = ?", [(int)$_GET['delete']]);
        $success = 'Site erfolgreich gelöscht!';
    } else {
        $error = 'Ungültiges Sicherheits-Token';
    }
}

// Site aktivieren/deaktivieren
if (isset($_GET['toggle']) && isset($_GET['token'])) {
    if ($auth->validateCsrfToken($_GET['token'])) {
        $site = $db->fetchOne("SELECT is_active FROM sites WHERE id = ?", [(int)$_GET['toggle']]);
        if ($site) {
            $newStatus = $site['is_active'] ? 0 : 1;
            $db->query("UPDATE sites SET is_active = ? WHERE id = ?", [$newStatus, (int)$_GET['toggle']]);
            $success = 'Site-Status aktualisiert!';
        }
    }
}

// Alle Sites abrufen
$sites = $db->fetchAll("SELECT * FROM sites ORDER BY name");
$currentUser = $auth->getCurrentUser();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sites verwalten - <?= htmlspecialchars($appTitle) ?></title>
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
        .btn-success {
            background: #28a745;
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
            margin-bottom: 20px;
        }
        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
        }
        .card-title { font-size: 18px; font-weight: 600; color: #333; }
        .card-body { padding: 25px; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        input[type="text"],
        input[type="password"],
        input[type="url"] {
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
        }
        .checkbox-group input {
            width: auto;
        }
        .alert {
            padding: 14px;
            border-radius: 10px;
            margin-bottom: 25px;
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
        .sites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .site-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
        }
        .site-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        .site-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        .site-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .site-id {
            font-size: 12px;
            color: #999;
            font-family: monospace;
        }
        .site-info {
            margin: 15px 0;
            font-size: 13px;
            color: #666;
        }
        .site-info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .site-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
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
                <li><a href="sites.php" class="active"><i class="fas fa-map-marker-alt"></i> Sites verwalten</a></li>
                <li><a href="users.php"><i class="fas fa-users"></i> Benutzer verwalten</a></li>
                <li><a href="vouchers.php"><i class="fas fa-ticket-alt"></i> Voucher-Historie</a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> Einstellungen</a></li>
            </ul>
        </nav>
    </div>
    
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Sites verwalten</h1>
            <button onclick="openModal()" class="btn btn-primary">
                <i class="fas fa-plus"></i> Neue Site hinzufügen
            </button>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (empty($sites)): ?>
            <div class="card">
                <div class="card-body" style="text-align: center; padding: 60px 20px; color: #999;">
                    <i class="fas fa-map-marker-alt" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
                    <p>Noch keine Sites konfiguriert.<br>Fügen Sie Ihre erste Site hinzu!</p>
                </div>
            </div>
        <?php else: ?>
            <div class="sites-grid">
                <?php foreach ($sites as $site): ?>
                <div class="site-card">
                    <div class="site-card-header">
                        <div>
                            <div class="site-name"><?= htmlspecialchars($site['name']) ?></div>
                            <div class="site-id">ID: <?= htmlspecialchars($site['site_id']) ?></div>
                        </div>
                        <div>
                            <?php if ($site['is_active']): ?>
                                <span class="badge badge-success"><i class="fas fa-check"></i> Aktiv</span>
                            <?php else: ?>
                                <span class="badge badge-warning"><i class="fas fa-pause"></i> Inaktiv</span>
                            <?php endif; ?>
                            <?php if ($site['public_access']): ?>
                                <span class="badge badge-info"><i class="fas fa-globe"></i> Öffentlich</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="site-info">
                        <div class="site-info-item">
                            <i class="fas fa-server" style="color: #667eea;"></i>
                            <span><?= htmlspecialchars($site['unifi_controller_url']) ?></span>
                        </div>
                        <div class="site-info-item">
                            <i class="fas fa-user" style="color: #667eea;"></i>
                            <span><?= htmlspecialchars($site['unifi_username']) ?></span>
                        </div>
                        <div class="site-info-item">
                            <i class="fas fa-clock" style="color: #999;"></i>
                            <span>Erstellt: <?= date('d.m.Y', strtotime($site['created_at'])) ?></span>
                        </div>
                    </div>
                    
                    <div class="site-actions">
                        <button onclick="openEditModal(<?= $site['id'] ?>, '<?= htmlspecialchars($site['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($site['site_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($site['unifi_controller_url'], ENT_QUOTES) ?>', '<?= htmlspecialchars($site['unifi_username'], ENT_QUOTES) ?>', <?= $site['public_access'] ?>)" 
                                class="btn btn-secondary btn-small">
                            <i class="fas fa-edit"></i> Bearbeiten
                        </button>
                        <a href="?toggle=<?= $site['id'] ?>&token=<?= $auth->getCsrfToken() ?>" 
                           class="btn btn-secondary btn-small">
                            <i class="fas fa-<?= $site['is_active'] ? 'pause' : 'play' ?>"></i>
                            <?= $site['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>
                        </a>
                        <a href="?delete=<?= $site['id'] ?>&token=<?= $auth->getCsrfToken() ?>" 
                           class="btn btn-danger btn-small"
                           onclick="return confirm('Möchten Sie diese Site wirklich löschen?')">
                            <i class="fas fa-trash"></i> Löschen
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal für neue Site -->
    <div id="addSiteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Neue Site hinzufügen</h2>
                <button class="modal-close" onclick="closeModal('addSiteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" id="addSiteForm">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    
                    <div class="form-group">
                        <label for="name">Site-Name *</label>
                        <input type="text" id="name" name="name" required 
                               placeholder="z.B. Hauptgebäude">
                    </div>
                    
                    <div class="form-group">
                        <label for="site_id">UniFi Site ID *</label>
                        <input type="text" id="site_id" name="site_id" required 
                               placeholder="z.B. default">
                        <small style="color: #999; font-size: 12px;">
                            Zu finden in der UniFi Controller URL oder in den Site-Einstellungen
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="controller_url">Controller URL *</label>
                        <input type="url" id="controller_url" name="controller_url" required 
                               placeholder="https://unifi.example.com:8443">
                        <small style="color: #999; font-size: 12px;">
                            Vollständige URL inklusive Port (meist 8443)
                        </small>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username">Benutzername *</label>
                            <input type="text" id="username" name="username" required 
                                   placeholder="admin">
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Passwort *</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="public_access" name="public_access">
                        <label for="public_access" style="margin: 0;">
                            Öffentlicher Zugriff (ohne Login nutzbar)
                        </label>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 25px;">
                        <button type="submit" name="add_site" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Site hinzufügen
                        </button>
                        <button type="button" onclick="closeModal('addSiteModal')" class="btn btn-secondary">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal für Site bearbeiten -->
    <div id="editSiteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Site bearbeiten</h2>
                <button class="modal-close" onclick="closeModal('editSiteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" id="editSiteForm">
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                    <input type="hidden" name="site_id" id="edit_site_id">
                    
                    <div class="form-group">
                        <label for="edit_name">Site-Name *</label>
                        <input type="text" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_site_id_str">UniFi Site ID *</label>
                        <input type="text" id="edit_site_id_str" name="site_id_str" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_controller_url">Controller URL *</label>
                        <input type="url" id="edit_controller_url" name="controller_url" required>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_username">Benutzername *</label>
                            <input type="text" id="edit_username" name="username" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_password">Neues Passwort</label>
                            <input type="password" id="edit_password" name="password" placeholder="Leer lassen = nicht ändern">
                            <small style="color: #999; font-size: 12px;">
                                Nur ausfüllen wenn Sie das Passwort ändern möchten
                            </small>
                        </div>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="edit_public_access" name="public_access">
                        <label for="edit_public_access" style="margin: 0;">
                            Öffentlicher Zugriff (ohne Login nutzbar)
                        </label>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 25px;">
                        <button type="submit" name="edit_site" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Änderungen speichern
                        </button>
                        <button type="button" onclick="closeModal('editSiteModal')" class="btn btn-secondary">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openModal() {
            document.getElementById('addSiteModal').classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function openEditModal(id, name, siteIdStr, controllerUrl, username, publicAccess) {
            document.getElementById('edit_site_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_site_id_str').value = siteIdStr;
            document.getElementById('edit_controller_url').value = controllerUrl;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_password').value = '';
            document.getElementById('edit_public_access').checked = publicAccess == 1;
            
            document.getElementById('editSiteModal').classList.add('active');
        }
        
        // Modal schließen bei Klick außerhalb
        document.getElementById('addSiteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal('addSiteModal');
            }
        });
        
        document.getElementById('editSiteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal('editSiteModal');
            }
        });
    </script>
</body>
</html>