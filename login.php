<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/I18n.php';

try {
    $auth = new Auth();
    if ($auth->isLoggedIn()) { header('Location: index.php'); exit; }
} catch (Exception $e) {
    die('Fehler beim Initialisieren: ' . $e->getMessage());
}

I18n::init();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = __('login_error_empty');
        } else {
            $result = $auth->login($email, $password);
            if ($result === true) {
                header('Location: index.php');
                exit;
            } elseif ($result === 'rate_limited') {
                $error = __('login_error_rate');
            } else {
                $error = __('login_error_creds');
            }
        }
    } catch (Exception $e) {
        $error = 'Login-Fehler: ' . $e->getMessage();
    }
}

try {
    $db       = Database::getInstance();
    $appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
    $logoUrl  = $db->getSetting('logo_url', '');

    $m365ClientId     = $db->getSetting('m365_client_id', '');
    $m365ClientSecret = $db->getSetting('m365_client_secret', '');
    $m365TenantId     = $db->getSetting('m365_tenant_id', '');
    $m365Enabled = !empty($m365ClientId) && !empty($m365ClientSecret) && !empty($m365TenantId);
    $publicAccess     = $db->getSetting('public_access', 0);
    $smtpEnabled      = $db->getSetting('smtp_enabled', '0') === '1';

    $m365LoginUrl = '';
    if ($m365Enabled) {
        $protocol   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host       = $_SERVER['HTTP_HOST'];
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        $scriptPath = $scriptPath === '/' ? '' : $scriptPath;
        $redirectUri = $protocol . '://' . $host . $scriptPath . '/m365_callback.php';
        $params = [
            'client_id'     => $m365ClientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'response_mode' => 'query',
            'scope'         => 'openid profile email User.Read',
            'state'         => bin2hex(random_bytes(16))
        ];
        $_SESSION['m365_state'] = $params['state'];
        $m365LoginUrl = "https://login.microsoftonline.com/$m365TenantId/oauth2/v2.0/authorize?" . http_build_query($params);
    }

    $showLocalLogin = isset($_GET['local']) && $_GET['local'] === '1';

} catch (Exception $e) {
    die('Datenbankfehler: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('login_title') ?> – <?= htmlspecialchars($appTitle) ?></title>
    <link rel="stylesheet" href="assets/global.css">
    <script>(function(){ const t=localStorage.getItem('theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-container { background: var(--bg-card); border-radius: 20px; box-shadow: 0 20px 60px var(--shadow-lg); max-width: 420px; width: 100%; padding: 50px 40px; text-align: center; }
        .logo { max-width: 200px; height: auto; margin-bottom: 30px; }
        h1 { color: var(--text-primary); font-size: 28px; margin-bottom: 10px; }
        .subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; text-align: left; }
        label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
        input[type="email"], input[type="password"] { width: 100%; padding: 14px; border: 2px solid var(--border-color); border-radius: 10px; font-size: 15px; background: var(--bg-input); color: var(--text-primary); transition: all 0.2s; }
        input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
        .btn { width: 100%; padding: 14px; background: var(--accent); color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-top: 10px; }
        .btn:hover { background: var(--accent-hover); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
        .btn-microsoft { background: #2f2f2f; color: white; border: none; margin-top: 0; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 12px; padding: 16px 24px; border-radius: 10px; width: 100%; font-size: 15px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .btn-microsoft:hover { background: #1a1a1a; transform: translateY(-2px); }
        .btn-microsoft svg { width: 20px; height: 20px; }
        .divider { margin: 22px 0; text-align: center; position: relative; }
        .divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: var(--border-color); }
        .divider span { background: var(--bg-card); padding: 0 15px; color: var(--text-muted); font-size: 13px; position: relative; z-index: 1; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-error   { background: #fee; border: 1px solid #fcc; color: #c33; }
        .alert-success { background: #efe; border: 1px solid #cfc; color: #3c3; }
        .back-link { display: block; margin-top: 20px; color: var(--accent); text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
        .local-login-link { display: block; margin-top: 20px; color: var(--text-muted); text-decoration: none; font-size: 13px; }
        .local-login-link:hover { color: var(--accent); text-decoration: underline; }
        .forgot-link { display: block; margin-top: 12px; text-align: right; color: var(--text-muted); font-size: 13px; text-decoration: none; }
        .forgot-link:hover { color: var(--accent); text-decoration: underline; }
        .header-tools { position: absolute; top: 20px; right: 20px; display: flex; gap: 8px; }
    </style>
</head>
<body>
<div style="position:fixed;top:15px;right:20px;display:flex;gap:8px;z-index:10;">
    <div class="lang-switcher">
        <?php foreach (I18n::getAvailable() as $code => $label): ?>
        <button class="lang-btn <?= I18n::getLanguage() === $code ? 'active' : '' ?>"
                onclick="switchLanguage('<?= $code ?>')"><?= strtoupper($code) ?></button>
        <?php endforeach; ?>
    </div>
    <button id="darkModeBtn" class="dark-mode-toggle" onclick="toggleDarkMode()" title="Dark Mode">🌙</button>
</div>

<div class="login-container">
    <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="logo">
    <?php else: ?>
        <h1><?= htmlspecialchars($appTitle) ?></h1>
    <?php endif; ?>

    <p class="subtitle"><?= __('login_subtitle') ?></p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($m365Enabled && !$showLocalLogin): ?>
        <a href="<?= htmlspecialchars($m365LoginUrl) ?>" class="btn-microsoft">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 23 23">
                <path fill="#f35325" d="M1 1h10v10H1z"/>
                <path fill="#81bc06" d="M12 1h10v10H12z"/>
                <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                <path fill="#ffba08" d="M12 12h10v10H12z"/>
            </svg>
            <?= __('login_ms') ?>
        </a>
        <a href="?local=1" class="local-login-link"><?= __('login_local') ?></a>
    <?php else: ?>
        <form method="post">
            <div class="form-group">
                <label for="email"><?= __('login_email') ?></label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            <div class="form-group">
                <label for="password"><?= __('login_password') ?></label>
                <input type="password" id="password" name="password" required>
            </div>
            <?php if ($smtpEnabled): ?>
            <a href="forgot_password.php" class="forgot-link"><?= __('login_forgot') ?></a>
            <?php endif; ?>
            <button type="submit" class="btn"><?= __('login_btn') ?></button>
        </form>

        <?php if ($m365Enabled): ?>
            <div class="divider"><span><?= __('or') ?></span></div>
            <a href="<?= htmlspecialchars($m365LoginUrl) ?>" class="btn-microsoft">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 23 23">
                    <path fill="#f35325" d="M1 1h10v10H1z"/>
                    <path fill="#81bc06" d="M12 1h10v10H12z"/>
                    <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                    <path fill="#ffba08" d="M12 12h10v10H12z"/>
                </svg>
                <?= __('login_ms') ?>
            </a>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($publicAccess): ?>
        <a href="index.php" class="back-link"><?= __('login_back') ?></a>
    <?php endif; ?>
</div>

<script src="assets/global.js"></script>
</body>
</html>
