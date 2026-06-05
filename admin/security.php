<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

$auth = new Auth();
$auth->requireLogin();

$db   = Database::getInstance();
$user = $auth->getCurrentUser();
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');

$error = '';
$success = '';
$hasPassword = !empty($user['password_hash']);
$totpEnabled = !empty($user['totp_enabled']);

// 2FA aktivieren (Code bestaetigen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enable_totp'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        $secret = $_SESSION['totp_setup_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');
        if ($secret === '') {
            $error = 'Setup abgelaufen, bitte erneut starten.';
        } elseif (!Totp::verify($secret, $code)) {
            $error = 'Code ungültig. Bitte erneut versuchen.';
        } else {
            $auth->enableTotp($user['id'], $secret);
            unset($_SESSION['totp_setup_secret']);
            $totpEnabled = true;
            $success = 'Zwei-Faktor-Authentifizierung wurde aktiviert.';
        }
    }
}

// 2FA deaktivieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_totp'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        $auth->disableTotp($user['id']);
        $totpEnabled = false;
        $success = 'Zwei-Faktor-Authentifizierung wurde deaktiviert.';
    }
}

// Für die Setup-Ansicht ein Secret erzeugen (in Session halten bis bestätigt)
$setupSecret = '';
$otpUri = '';
if (!$totpEnabled && $hasPassword) {
    $setupSecret = $_SESSION['totp_setup_secret'] ?? Totp::generateSecret();
    $_SESSION['totp_setup_secret'] = $setupSecret;
    $otpUri = Totp::provisioningUri($setupSecret, $user['email'], $appTitle);
}
$csrf = $auth->getCsrfToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zwei-Faktor-Authentifizierung – <?= htmlspecialchars($appTitle) ?></title>
<?php if (!$totpEnabled && $hasPassword): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSe2keRB6Q5pBUtIxCY7bQMsVB0ANBpd6JDg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php endif; ?>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
body { background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.card { background:#fff; border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.3); max-width:480px; width:100%; padding:36px; }
h1 { font-size:22px; color:#333; margin-bottom:6px; }
.sub { color:#777; font-size:14px; margin-bottom:24px; }
.alert { padding:12px 14px; border-radius:9px; font-size:14px; margin-bottom:18px; }
.alert-error { background:#fee; border:1px solid #fcc; color:#c33; }
.alert-ok { background:#efe; border:1px solid #cfc; color:#2a7; }
.status { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:8px; font-size:13px; font-weight:600; margin-bottom:20px; }
.on { background:#e3f6ea; color:#2a7; } .off { background:#fdeaea; color:#c33; }
.qr { display:flex; justify-content:center; margin:18px 0; }
.secret { font-family:monospace; background:#f5f6fa; padding:10px; border-radius:8px; text-align:center; letter-spacing:2px; word-break:break-all; font-size:14px; margin-bottom:18px; }
ol { margin:0 0 18px 18px; color:#555; font-size:14px; line-height:1.7; }
label { display:block; font-size:14px; color:#555; margin-bottom:8px; font-weight:500; }
input[type=text] { width:100%; padding:13px; border:2px solid #e0e0e0; border-radius:10px; font-size:18px; letter-spacing:6px; text-align:center; }
.btn { width:100%; padding:14px; border:none; border-radius:10px; font-size:15px; font-weight:600; cursor:pointer; margin-top:14px; }
.btn-primary { background:#667eea; color:#fff; } .btn-danger { background:#e25555; color:#fff; }
.back { display:block; text-align:center; margin-top:20px; color:#667eea; text-decoration:none; font-size:14px; }
</style>
</head>
<body>
<div class="card">
  <h1>🔐 Zwei-Faktor-Authentifizierung</h1>
  <p class="sub">Konto: <?= htmlspecialchars($user['email']) ?></p>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <?php if (!$hasPassword): ?>
    <div class="status off">● Nicht verfügbar</div>
    <p class="sub">Ihr Konto meldet sich über Microsoft 365 an. 2FA wird dort in Ihrem Microsoft-Konto verwaltet.</p>
  <?php elseif ($totpEnabled): ?>
    <div class="status on">● Aktiv</div>
    <p class="sub">Bei jeder Anmeldung wird zusätzlich ein Code aus Ihrer Authenticator-App abgefragt.</p>
    <form method="post" onsubmit="return confirm('2FA wirklich deaktivieren?');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" name="disable_totp" class="btn btn-danger">2FA deaktivieren</button>
    </form>
  <?php else: ?>
    <div class="status off">● Inaktiv</div>
    <ol>
      <li>Authenticator-App öffnen (Google Authenticator, Authy, Microsoft Authenticator …)</li>
      <li>QR-Code scannen <em>oder</em> Secret manuell eingeben</li>
      <li>Den angezeigten 6-stelligen Code unten eingeben</li>
    </ol>
    <div class="qr"><div id="qrcode"></div></div>
    <div class="secret"><?= htmlspecialchars($setupSecret) ?></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <label for="code">6-stelliger Code</label>
      <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required>
      <button type="submit" name="enable_totp" class="btn btn-primary">2FA aktivieren</button>
    </form>
    <script>
      new QRCode(document.getElementById('qrcode'), {
        text: <?= json_encode($otpUri) ?>, width: 180, height: 180,
        correctLevel: QRCode.CorrectLevel.M
      });
    </script>
  <?php endif; ?>

  <a class="back" href="../index.php">← Zurück</a>
</div>
</body>
</html>
