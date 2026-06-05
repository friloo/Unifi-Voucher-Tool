<?php
/**
 * Shared admin navigation/header snippet.
 * Expects $currentPage (string), $appTitle (string), $auth, $db to be set before include.
 * Expects I18n to be initialized.
 */
$currentPage = $currentPage ?? '';
$faviconUrl  = isset($db) ? $db->getSetting('favicon_url', '') : '';
$currentUser = isset($auth) ? $auth->getCurrentUser() : null;
$lang        = I18n::getLanguage();
?>
    <?php if ($faviconUrl): ?>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $adminBase ?? '../' ?>assets/global.css">
    <script>
        (function(){
            const t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <style>
        /* Base admin layout using CSS variables */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background: var(--bg-body); color: var(--text-primary); }
        .header { background: var(--bg-header); border-bottom: 1px solid var(--border-color); padding: 0 30px; height: 70px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 150; box-shadow: 0 2px 10px var(--shadow); }
        .header-left { display: flex; align-items: center; gap: 12px; }
        .header-title { font-size: 20px; font-weight: 600; color: var(--text-primary); }
        .header-right { display: flex; align-items: center; gap: 10px; }
        .sidebar { position: fixed; left: 0; top: 70px; bottom: 0; width: 260px; background: var(--bg-sidebar); border-right: 1px solid var(--border-color); padding: 20px 0; overflow-y: auto; z-index: 100; }
        .sidebar-nav { list-style: none; }
        .sidebar-nav li { margin-bottom: 2px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 25px; color: var(--text-secondary); text-decoration: none; transition: all 0.2s; font-size: 14px; border-radius: 0 8px 8px 0; margin-right: 12px; }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: var(--bg-hover); color: var(--accent); }
        .sidebar-nav i { width: 18px; text-align: center; font-size: 15px; }
        .sidebar-section { padding: 16px 25px 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); }
        .main-content { margin-left: 260px; padding: 30px; min-height: calc(100vh - 70px); }
        .user-menu { display: flex; align-items: center; gap: 10px; padding: 6px 12px; background: var(--bg-hover); border-radius: 10px; }
        .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px; flex-shrink: 0; }
        .user-name { font-weight: 500; font-size: 13px; color: var(--text-primary); }
        .btn { padding: 8px 16px; border-radius: 8px; border: none; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s; font-size: 13px; }
        .btn-secondary { background: var(--bg-hover); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--border-color); color: var(--text-primary); }
    </style>
</head>
<body>

<!-- Sidebar overlay for mobile -->
<div class="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<div class="header">
    <div class="header-left">
        <button class="mobile-menu-btn" onclick="toggleMobileSidebar()" title="Menu">
            <i class="fas fa-bars"></i>
        </button>
        <div class="header-title">
            <i class="fas fa-shield-alt" style="color: var(--accent);"></i>
            <?= __('nav_administration') ?>
        </div>
    </div>
    <div class="header-right">
        <!-- Language switcher -->
        <div class="lang-switcher">
            <?php foreach (I18n::getAvailable() as $code => $label): ?>
            <button class="lang-btn <?= $lang === $code ? 'active' : '' ?>"
                    onclick="switchLanguage('<?= $code ?>')"><?= strtoupper($code) ?></button>
            <?php endforeach; ?>
        </div>
        <!-- Dark mode toggle -->
        <button id="darkModeBtn" class="dark-mode-toggle" onclick="toggleDarkMode()" title="Dark Mode">🌙</button>
        <!-- Back link -->
        <a href="<?= $adminBase ?? '../' ?>index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i>
            <span class="hide-mobile"><?= __('nav_back') ?></span>
        </a>
        <!-- User menu -->
        <?php if ($currentUser): ?>
        <div class="user-menu">
            <div class="user-avatar"><?= strtoupper(mb_substr($currentUser['name'], 0, 1)) ?></div>
            <div class="user-name hide-mobile"><?= htmlspecialchars($currentUser['name']) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="sidebar" id="adminSidebar">
    <nav>
        <div class="sidebar-section">Main</div>
        <ul class="sidebar-nav">
            <li><a href="<?= $adminBase ?? '' ?>index.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-home"></i> <?= __('nav_dashboard') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>sites.php" class="<?= $currentPage === 'sites' ? 'active' : '' ?>">
                <i class="fas fa-map-marker-alt"></i> <?= __('nav_sites') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>users.php" class="<?= $currentPage === 'users' ? 'active' : '' ?>">
                <i class="fas fa-users"></i> <?= __('nav_users') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>vouchers.php" class="<?= $currentPage === 'vouchers' ? 'active' : '' ?>">
                <i class="fas fa-ticket-alt"></i> <?= __('nav_vouchers') ?>
            </a></li>
        </ul>
        <div class="sidebar-section" style="margin-top: 10px;">Tools</div>
        <ul class="sidebar-nav">
            <li><a href="<?= $adminBase ?? '' ?>templates.php" class="<?= $currentPage === 'templates' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i> <?= __('nav_templates') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>audit_log.php" class="<?= $currentPage === 'audit_log' ? 'active' : '' ?>">
                <i class="fas fa-history"></i> <?= __('nav_audit_log') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> <?= __('nav_settings') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>api_keys.php" class="<?= $currentPage === 'api_keys' ? 'active' : '' ?>">
                <i class="fas fa-key"></i> <?= __('nav_api_keys') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>security.php" class="<?= $currentPage === 'security' ? 'active' : '' ?>">
                <i class="fas fa-user-shield"></i> <?= __('nav_security') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>integrations.php" class="<?= $currentPage === 'integrations' ? 'active' : '' ?>">
                <i class="fas fa-plug"></i> <?= __('nav_integrations') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>backup.php" class="<?= $currentPage === 'backup' ? 'active' : '' ?>">
                <i class="fas fa-database"></i> <?= __('nav_backup') ?>
            </a></li>
            <li><a href="<?= $adminBase ?? '' ?>update.php" class="<?= $currentPage === 'update' ? 'active' : '' ?>">
                <i class="fas fa-sync-alt"></i> <?= __('nav_update') ?>
            </a></li>
        </ul>
    </nav>
</div>

<div class="main-content">
<style>
.hide-mobile { }
@media(max-width:600px) { .hide-mobile { display: none; } }
</style>
