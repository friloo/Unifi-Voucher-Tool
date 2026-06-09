<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/Mailer.php';
require_once __DIR__ . '/includes/I18n.php';

$auth = new Auth();
if ($auth->isLoggedIn()) { header('Location: index.php'); exit; }
I18n::init();

$db       = Database::getInstance();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
$logoUrl  = $db->getSetting('logo_url', '');
$systemUrl = rtrim($db->getSetting('system_url', ''), '/');

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    // Einfacher Throttle: max. 3 Anfragen pro 15 Minuten je Session (gegen Spam)
    $now = time();
    $rl = array_values(array_filter($_SESSION['pwreset_times'] ?? [], fn($t) => ($now - $t) < 900));
    if (count($rl) >= 3) {
        $error = 'Zu viele Anfragen. Bitte warten Sie einige Minuten.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = __('error_email_invalid');
    } else {
        $rl[] = $now;
        $_SESSION['pwreset_times'] = $rl;
        $user = $db->fetchOne("SELECT * FROM users WHERE email = ? AND is_active = 1 AND password_hash IS NOT NULL", [$email]);

        // Always show success (don't reveal whether email exists)
        if ($user) {
            try {
                // Delete old tokens for this user
                $db->execute("DELETE FROM password_reset_tokens WHERE user_id = ?", [$user['id']]);

                // Generate token
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $db->execute(
                    "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
                    [$user['id'], $token, $expiresAt]
                );

                // Send email
                $resetUrl  = $systemUrl . '/reset_password.php?token=' . $token;
                $mailer    = new Mailer();
                $subject   = $appTitle . ' – Passwort zurücksetzen';
                $body      = "Hallo {$user['name']},\n\n" .
                             "Sie haben eine Passwort-Rücksetzung angefordert.\n\n" .
                             "Klicken Sie auf den folgenden Link, um Ihr Passwort zurückzusetzen (gültig für 1 Stunde):\n\n" .
                             $resetUrl . "\n\n" .
                             "Falls Sie dies nicht angefordert haben, ignorieren Sie diese E-Mail.\n\n" .
                             $appTitle;
                $mailer->sendRaw($user['email'], $subject, $body);

                // Audit log
                $db->execute(
                    "INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, 'password_reset', 'user', ?, 'Reset-Link angefordert', ?)",
                    [$user['id'], $user['id'], $_SERVER['REMOTE_ADDR'] ?? '']
                );
            } catch (Exception $e) {
                // Silent – don't reveal errors to user
            }
        }

        $success = __('reset_success');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('reset_title') ?> – <?= htmlspecialchars($appTitle) ?></title>
    <link rel="stylesheet" href="assets/global.css">
    <script>(function(){ const t=localStorage.getItem('theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .box { background: var(--bg-card); border-radius: 20px; box-shadow: 0 20px 60px var(--shadow-lg); max-width: 420px; width: 100%; padding: 45px 40px; text-align: center; }
        .logo { max-width: 180px; height: auto; margin-bottom: 25px; }
        h1 { color: var(--text-primary); font-size: 26px; margin-bottom: 8px; }
        .subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 28px; line-height: 1.5; }
        .form-group { margin-bottom: 18px; text-align: left; }
        label { display: block; margin-bottom: 7px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
        input[type="email"] { width: 100%; padding: 13px; border: 2px solid var(--border-color); border-radius: 10px; font-size: 15px; background: var(--bg-input); color: var(--text-primary); transition: border-color 0.2s; }
        input:focus { outline: none; border-color: var(--accent); }
        .btn { width: 100%; padding: 14px; background: var(--accent); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-top: 8px; }
        .btn:hover { background: var(--accent-hover); transform: translateY(-2px); }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: left; }
        .alert-error   { background: #fee; border: 1px solid #fcc; color: #c33; }
        .alert-success { background: #efe; border: 1px solid #cfc; color: #3c3; }
        .back-link { display: block; margin-top: 22px; color: var(--accent); text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="box">
    <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="logo">
    <?php else: ?>
        <h1><?= htmlspecialchars($appTitle) ?></h1>
    <?php endif; ?>

    <h1><?= __('reset_title') ?></h1>
    <p class="subtitle"><?= __('reset_subtitle') ?></p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="post">
        <div class="form-group">
            <label for="email"><?= __('reset_email_label') ?></label>
            <input type="email" id="email" name="email" required autofocus placeholder="name@example.com">
        </div>
        <button type="submit" class="btn"><?= __('reset_send_btn') ?></button>
    </form>
    <?php endif; ?>

    <a href="login.php" class="back-link"><?= __('reset_back_login') ?></a>
</div>
<script src="assets/global.js"></script>
</body>
</html>
