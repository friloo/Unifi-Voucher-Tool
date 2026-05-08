<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/I18n.php';

$auth = new Auth();
if ($auth->isLoggedIn()) { header('Location: index.php'); exit; }
I18n::init();

$db       = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
$logoUrl  = $db->getSetting('logo_url', '');

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = '';
$valid   = false;
$tokenRow = null;

if (empty($token)) {
    $error = __('reset_invalid');
} else {
    $tokenRow = $db->fetchOne(
        "SELECT prt.*, u.email, u.name FROM password_reset_tokens prt
         JOIN users u ON prt.user_id = u.id
         WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()",
        [$token]
    );
    if (!$tokenRow) {
        $error = __('reset_invalid');
    } else {
        $valid = true;
    }
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPw  = $_POST['new_password'] ?? '';
    $confirm= $_POST['confirm_password'] ?? '';

    if (strlen($newPw) < 8) {
        $error = __('settings_pw_minlength');
        $valid = true; // keep form visible
    } elseif ($newPw !== $confirm) {
        $error = 'Passwörter stimmen nicht überein';
        $valid = true;
    } else {
        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        $db->execute("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $tokenRow['user_id']]);
        $db->execute("UPDATE password_reset_tokens SET used = 1 WHERE token = ?", [$token]);

        $db->execute(
            "INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, 'password_reset', 'user', ?, 'Passwort erfolgreich geändert', ?)",
            [$tokenRow['user_id'], $tokenRow['user_id'], $_SERVER['REMOTE_ADDR'] ?? '']
        );

        $success = __('reset_done');
        $valid   = false;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('reset_new_pw') ?> – <?= htmlspecialchars($appTitle) ?></title>
    <link rel="stylesheet" href="assets/global.css">
    <script>(function(){ const t=localStorage.getItem('theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .box { background: var(--bg-card); border-radius: 20px; box-shadow: 0 20px 60px var(--shadow-lg); max-width: 420px; width: 100%; padding: 45px 40px; text-align: center; }
        .logo { max-width: 180px; height: auto; margin-bottom: 25px; }
        h1 { color: var(--text-primary); font-size: 24px; margin-bottom: 8px; }
        .subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 28px; }
        .form-group { margin-bottom: 18px; text-align: left; }
        label { display: block; margin-bottom: 7px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
        input[type="password"] { width: 100%; padding: 13px; border: 2px solid var(--border-color); border-radius: 10px; font-size: 15px; background: var(--bg-input); color: var(--text-primary); transition: border-color 0.2s; }
        input:focus { outline: none; border-color: var(--accent); }
        .btn { width: 100%; padding: 14px; background: var(--accent); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-top: 8px; }
        .btn:hover { background: var(--accent-hover); transform: translateY(-2px); }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: left; }
        .alert-error   { background: #fee; border: 1px solid #fcc; color: #c33; }
        .alert-success { background: #efe; border: 1px solid #cfc; color: #3c3; }
        .back-link { display: block; margin-top: 22px; color: var(--accent); text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
        .pw-strength { height: 4px; border-radius: 2px; margin-top: 6px; transition: all 0.3s; background: var(--border-color); }
        .pw-strength.weak   { background: var(--danger); width: 30%; }
        .pw-strength.medium { background: var(--warning); width: 65%; }
        .pw-strength.strong { background: var(--success); width: 100%; }
    </style>
</head>
<body>
<div class="box">
    <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="logo">
    <?php else: ?>
        <div style="font-size:28px;font-weight:700;color:var(--text-primary);margin-bottom:10px;"><?= htmlspecialchars($appTitle) ?></div>
    <?php endif; ?>

    <h1><?= __('reset_new_pw') ?></h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($valid): ?>
    <p class="subtitle">Für <strong><?= htmlspecialchars($tokenRow['email']) ?></strong></p>
    <form method="post" action="reset_password.php?token=<?= htmlspecialchars($token) ?>">
        <div class="form-group">
            <label><?= __('reset_new_pw_label') ?></label>
            <input type="password" name="new_password" id="pw" required minlength="8" oninput="checkPw(this.value)">
            <div class="pw-strength" id="pwBar"></div>
        </div>
        <div class="form-group">
            <label><?= __('reset_confirm_label') ?></label>
            <input type="password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn"><?= __('reset_set_btn') ?></button>
    </form>
    <?php elseif ($success): ?>
        <a href="login.php" class="btn" style="display:block;text-decoration:none;text-align:center;"><?= __('btn_login') ?></a>
    <?php endif; ?>

    <a href="login.php" class="back-link"><?= __('reset_back_login') ?></a>
</div>

<script>
function checkPw(val) {
    const bar = document.getElementById('pwBar');
    if (!bar) return;
    if (val.length >= 12 && /[A-Z]/.test(val) && /[0-9]/.test(val)) {
        bar.className = 'pw-strength strong';
    } else if (val.length >= 8) {
        bar.className = 'pw-strength medium';
    } else if (val.length > 0) {
        bar.className = 'pw-strength weak';
    } else {
        bar.className = 'pw-strength';
    }
}
</script>
</body>
</html>
