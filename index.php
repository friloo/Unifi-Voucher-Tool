<?php
// Updater maintenance hook — see updater/README.md
$maintenanceFile = __DIR__ . '/updater/storage/.maintenance';
if (file_exists($maintenanceFile)) {
    http_response_code(503);
    require __DIR__ . '/updater/templates/maintenance.html';
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/UniFiController.php';
require_once __DIR__ . '/includes/Mailer.php';
require_once __DIR__ . '/includes/I18n.php';

$auth = new Auth();
$db = Database::getInstance();
$mailer = new Mailer();
I18n::init();

/**
 * Session-basierter Throttle fuer die anonyme oeffentliche Voucher-Erstellung.
 * Erlaubt max. 10 Erstellungen in 10 Minuten pro Session. Verhindert, dass
 * der oeffentliche Modus zum Spammen des UniFi-Controllers missbraucht wird.
 */
function isVoucherRateLimited() {
    $window = 600;   // 10 Minuten
    $maxRequests = 10;
    $now = time();
    $timestamps = $_SESSION['voucher_create_times'] ?? [];
    $timestamps = array_values(array_filter($timestamps, function ($t) use ($now, $window) {
        return ($now - $t) < $window;
    }));
    if (count($timestamps) >= $maxRequests) {
        $_SESSION['voucher_create_times'] = $timestamps;
        return true;
    }
    $timestamps[] = $now;
    $_SESSION['voucher_create_times'] = $timestamps;
    return false;
}

$appTitle          = $db->getSetting('app_title', 'UniFi Voucher System');
$logoUrl           = $db->getSetting('logo_url', '');
$instructionHeader = $db->getSetting('instruction_header', '');
$instructionText   = $db->getSetting('instruction_text', '');
$publicAccess      = $db->getSetting('public_access', 0);
$smtpEnabled       = $db->getSetting('smtp_enabled', '0') === '1';
$printTemplate     = $db->getSetting('print_template', '<div style="text-align:center;padding:40px;font-family:sans-serif;"><h1>{APP_TITLE}</h1><h2>WLAN Zugangscode</h2><div style="font-size:48px;font-weight:bold;margin:30px 0;font-family:monospace;letter-spacing:4px;">{VOUCHER_CODE}</div><p><strong>Gültig bis:</strong> {EXPIRY_DATE} {EXPIRY_TIME}</p><p><strong>Standort:</strong> {SITE_NAME}</p><p><strong>Maximale Geräte:</strong> {MAX_USES}</p><hr style="margin:30px 0;"><div style="font-size:14px;text-align:left;">{INSTRUCTIONS}</div></div>');
$defaultExpire     = max(1, (int)$db->getSetting('default_expire_minutes', 480));
$defaultMaxUses    = max(1, (int)$db->getSetting('default_max_uses', 1));
$maxUsesLimit      = max(1, (int)$db->getSetting('max_uses_limit', 10));

if (!$publicAccess && !$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

try {
    $templates = $db->fetchAll("SELECT * FROM voucher_templates WHERE is_active = 1 ORDER BY name");
} catch (Exception $e) {
    $templates = [];
}

$error        = '';
$success      = '';
$voucherCode  = '';
$voucherCreated = false;
$voucherData  = [];
$bulkVouchers = [];
$bulkCreated  = false;

if ($auth->isLoggedIn()) {
    if ($auth->isAdmin()) {
        $sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1 ORDER BY name");
    } else {
        $sites = $db->fetchAll(
            "SELECT s.* FROM sites s INNER JOIN user_site_access usa ON s.id = usa.site_id
             WHERE s.is_active = 1 AND usa.user_id = ? ORDER BY s.name",
            [$_SESSION['user_id']]
        );
    }
} else {
    $sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1 AND public_access = 1 ORDER BY name");
}

$autoSelectSite = (count($sites) === 1) ? $sites[0]['id'] : 0;

// Helper: create one voucher and save to DB
function doCreateVoucher($db, $site, $voucherName, $maxUses, $expireMinutes, $userId, $qos = []) {
    $datum          = date('Y-m-d');
    $fullName       = $datum . '_' . $voucherName;
    $controller     = new UniFiController(
        $site['unifi_controller_url'],
        $site['unifi_username'],
        Crypto::decrypt($site['unifi_password']),
        $site['site_id']
    );
    $voucher = $controller->createVoucher($fullName, $maxUses, $expireMinutes, $qos);
    if (!is_array($voucher) || empty($voucher['formatted_code'])) {
        throw new Exception(__('error_voucher_invalid'));
    }
    $db->execute(
        "INSERT INTO vouchers (site_id, user_id, voucher_code, voucher_name, max_uses, expire_minutes, unifi_voucher_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$site['id'], $userId, $voucher['code'], $fullName, $maxUses, $expireMinutes, $voucher['unifi_id'] ?? null]
    );
    $expiryTs = time() + ($expireMinutes * 60);
    return [
        'code'        => $voucher['formatted_code'],
        'site_name'   => $site['name'],
        'max_uses'    => $maxUses,
        'expire_min'  => $expireMinutes,
        'expiry_date' => date('d.m.Y', $expiryTs),
        'expiry_time' => date('H:i', $expiryTs),
    ];
}

// Single voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_voucher'])) {
    if (!$publicAccess && !$auth->isLoggedIn()) {
        $error = __('error_login_req');
    } elseif (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        // CSRF fuer ALLE (auch anonyme oeffentliche Erstellung)
        $error = __('error_csrf');
    } elseif (!$auth->isLoggedIn() && isVoucherRateLimited()) {
        $error = 'Zu viele Anfragen. Bitte warten Sie einen Moment.';
    } else {
        try {
            $siteId        = (int)($_POST['site_id'] ?? 0);
            $voucherName   = trim((string)($_POST['voucher_name'] ?? ''));
            $maxUses       = (int)($_POST['max_uses'] ?? $defaultMaxUses);
            $expireMinutes = max(1, (int)($_POST['expire_minutes'] ?? $defaultExpire));
            $sendEmail     = isset($_POST['send_email']) && !empty($_POST['recipient_email']);
            $recipientEmail= trim((string)($_POST['recipient_email'] ?? ''));

            if (empty($voucherName)) throw new Exception(__('error_name_req'));
            if ($maxUses < 1 || $maxUses > $maxUsesLimit) throw new Exception(__('error_devices_range', ['max' => $maxUsesLimit]));
            if ($siteId <= 0) throw new Exception(__('error_site_req'));
            if ($sendEmail && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) throw new Exception(__('error_email_invalid'));

            if ($auth->isLoggedIn() && !$auth->hasAccessToSite($siteId)) throw new Exception(__('error_site_no_perm'));

            $site = $db->fetchOne("SELECT * FROM sites WHERE id = ? AND is_active = 1", [$siteId]);
            if (!$site) throw new Exception(__('error_site_not_found'));

            $userId  = $auth->isLoggedIn() ? ($_SESSION['user_id'] ?? null) : null;
            $qos = [
                'down'     => max(0, (int)($_POST['qos_down'] ?? 0)),
                'up'       => max(0, (int)($_POST['qos_up'] ?? 0)),
                'quota_mb' => max(0, (int)($_POST['qos_quota'] ?? 0)),
            ];
            $voucherData = doCreateVoucher($db, $site, $voucherName, $maxUses, $expireMinutes, $userId, $qos);
            $voucherCode  = $voucherData['code'];
            $voucherCreated = true;

            if ($sendEmail && !empty($recipientEmail)) {
                $mailer->sendVoucherEmail($recipientEmail, $voucherCode, $site['name'], $maxUses);
                $success = 'Voucher erstellt. E-Mail versendet.';
            } else {
                $success = 'Voucher erfolgreich erstellt!';
            }
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// Bulk voucher creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_bulk'])) {
    if (!$publicAccess && !$auth->isLoggedIn()) {
        $error = __('error_login_req');
    } elseif (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        // CSRF fuer ALLE (auch anonyme oeffentliche Erstellung)
        $error = __('error_csrf');
    } elseif (!$auth->isLoggedIn() && isVoucherRateLimited()) {
        $error = 'Zu viele Anfragen. Bitte warten Sie einen Moment.';
    } else {
        try {
            $siteId        = (int)($_POST['site_id'] ?? 0);
            $voucherName   = trim((string)($_POST['voucher_name'] ?? ''));
            $maxUses       = (int)($_POST['max_uses'] ?? $defaultMaxUses);
            $expireMinutes = max(1, (int)($_POST['expire_minutes'] ?? $defaultExpire));
            $bulkCount     = max(1, min(20, (int)($_POST['bulk_count'] ?? 1)));

            if (empty($voucherName)) throw new Exception(__('error_name_req'));
            if ($maxUses < 1 || $maxUses > $maxUsesLimit) throw new Exception(__('error_devices_range', ['max' => $maxUsesLimit]));
            if ($siteId <= 0) throw new Exception(__('error_site_req'));
            if ($auth->isLoggedIn() && !$auth->hasAccessToSite($siteId)) throw new Exception(__('error_site_no_perm'));

            $site = $db->fetchOne("SELECT * FROM sites WHERE id = ? AND is_active = 1", [$siteId]);
            if (!$site) throw new Exception(__('error_site_not_found'));

            $userId = $auth->isLoggedIn() ? ($_SESSION['user_id'] ?? null) : null;
            $qos = [
                'down'     => max(0, (int)($_POST['qos_down'] ?? 0)),
                'up'       => max(0, (int)($_POST['qos_up'] ?? 0)),
                'quota_mb' => max(0, (int)($_POST['qos_quota'] ?? 0)),
            ];

            for ($i = 0; $i < $bulkCount; $i++) {
                $bulkVouchers[] = doCreateVoucher($db, $site, $voucherName . '_' . ($i + 1), $maxUses, $expireMinutes, $userId, $qos);
            }

            $bulkCreated = true;
            $success = str_replace('{count}', $bulkCount, __('bulk_success'));
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

$currentUser = $auth->isLoggedIn() ? $auth->getCurrentUser() : null;

// Build print HTML for each voucher
function buildPrintCard($template, $data, $instructionHeader, $instructionText, $appTitle) {
    $instructions = $instructionHeader || $instructionText
        ? htmlspecialchars($instructionHeader) . "\n" . $instructionText
        : '';
    return str_replace(
        ['{VOUCHER_CODE}', '{SITE_NAME}', '{MAX_USES}', '{APP_TITLE}', '{INSTRUCTIONS}', '{EXPIRY_DATE}', '{EXPIRY_TIME}'],
        [$data['code'], htmlspecialchars($data['site_name']), $data['max_uses'], htmlspecialchars($appTitle), $instructions, $data['expiry_date'], $data['expiry_time']],
        $template
    );
}
?>
<!DOCTYPE html>
<html lang="<?= I18n::getLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appTitle) ?></title>
    <link rel="stylesheet" href="assets/global.css">
    <script>(function(){ const t=localStorage.getItem('theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
    <?php if ($voucherCreated): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSe2keRB6Q5pBUtIxCY7bQMsVB0ANBpd6JDg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .header { max-width: 1200px; margin: 0 auto 30px; display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.15); backdrop-filter: blur(10px); padding: 15px 25px; border-radius: 15px; }
        .header-title { color: white; font-size: 20px; font-weight: 600; }
        .header-right { display: flex; align-items: center; gap: 10px; }
        .btn-header { background: white; color: #667eea; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 14px; transition: all 0.2s; border: none; cursor: pointer; }
        .btn-header:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .container { max-width: 600px; margin: 0 auto; background: var(--bg-card); border-radius: 20px; box-shadow: 0 20px 60px var(--shadow-lg); padding: 40px; }
        h1 { text-align: center; color: var(--text-primary); margin-bottom: 30px; font-size: 28px; }
        .logo { max-width: 250px; display: block; margin: 0 auto 30px; }
        .alert { padding: 14px; border-radius: 10px; margin-bottom: 25px; font-size: 14px; }
        .alert-error   { background: #fee; border: 1px solid #fcc; color: #c33; }
        .alert-success { background: #efe; border: 1px solid #cfc; color: #3c3; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-weight: 500; font-size: 14px; }
        input[type="text"], input[type="number"], input[type="email"], select { width: 100%; padding: 14px; border: 2px solid var(--border-color); border-radius: 10px; font-size: 15px; transition: all 0.2s; background: var(--bg-input); color: var(--text-primary); }
        input:focus, select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
        .btn { width: 100%; padding: 16px; background: var(--accent); color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn:hover { background: var(--accent-hover); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
        .btn:disabled { background: #ccc; cursor: not-allowed; transform: none; }
        .btn-outline { background: transparent; color: var(--accent); border: 2px solid var(--accent); margin-top: 10px; }
        .btn-outline:hover { background: var(--accent); color: white; }
        .mode-tabs { display: flex; gap: 8px; margin-bottom: 25px; background: var(--bg-hover); padding: 6px; border-radius: 12px; }
        .mode-tab { flex: 1; padding: 10px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; background: transparent; color: var(--text-secondary); transition: all 0.2s; }
        .mode-tab.active { background: var(--bg-card); color: var(--accent); box-shadow: 0 2px 8px var(--shadow); }
        .template-dropdown { margin-bottom: 20px; padding: 15px; background: var(--bg-hover); border-radius: 12px; border: 2px solid var(--border-color); }
        .template-dropdown label { color: var(--text-secondary); font-size: 13px; margin-bottom: 6px; }
        .template-hint { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
        .voucher-result { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 15px; text-align: center; margin-bottom: 25px; }
        .voucher-code { font-size: 32px; font-weight: bold; letter-spacing: 2px; margin: 20px 0; font-family: 'Courier New', monospace; cursor: pointer; }
        .voucher-code:hover { opacity: 0.85; }
        .voucher-info { font-size: 14px; opacity: 0.9; margin-top: 10px; }
        .qr-wrapper { display: flex; flex-direction: column; align-items: center; margin: 20px 0 0; }
        .qr-wrapper canvas, .qr-wrapper img { border: 6px solid white; border-radius: 8px; }
        .qr-label { font-size: 12px; opacity: 0.8; margin-top: 8px; }
        .instruction-box { background: var(--bg-hover); padding: 20px; border-radius: 10px; margin-top: 25px; }
        .instruction-box h3 { color: var(--text-primary); margin-bottom: 10px; font-size: 16px; }
        .instruction-box p, .instruction-box div { color: var(--text-secondary); line-height: 1.6; font-size: 14px; }
        .empty-state { text-align: center; padding: 40px; color: var(--text-muted); }
        .email-option { background: var(--bg-hover); border: 2px solid var(--border-color); border-radius: 12px; padding: 20px; margin: 20px 0; transition: all 0.2s; }
        .email-option.active { background: var(--bg-hover); border-color: var(--accent); }
        .email-checkbox-wrapper { display: flex; align-items: center; gap: 12px; cursor: pointer; }
        .email-checkbox-wrapper input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; accent-color: var(--accent); }
        .email-checkbox-wrapper label { margin: 0; cursor: pointer; font-size: 15px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .email-input-wrapper { max-height: 0; overflow: hidden; opacity: 0; transition: all 0.3s; }
        .email-input-wrapper.show { max-height: 120px; opacity: 1; margin-top: 15px; }
        .bulk-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .bulk-table th { background: var(--bg-hover); color: var(--text-secondary); padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; }
        .bulk-table td { padding: 12px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); font-size: 14px; }
        .bulk-table tr:last-child td { border-bottom: none; }
        .bulk-table code { background: var(--bg-hover); padding: 4px 8px; border-radius: 4px; font-family: monospace; letter-spacing: 1px; cursor: pointer; }
        .bulk-table code:hover { opacity: 0.75; }
        .copy-hint { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @media print {
            body * { visibility: hidden; }
            #printArea, #printArea * { visibility: visible; }
            #printArea { position: absolute; left: 0; top: 0; width: 100%; background: white; }
            .no-print { display: none !important; }
            .print-page-break { page-break-after: always; }
        }
        @media (max-width: 480px) {
            .container { padding: 25px 20px; border-radius: 15px; }
            .header { padding: 12px 15px; }
        }
    </style>
</head>
<body>

<div style="position:fixed;top:15px;right:20px;display:flex;gap:8px;z-index:10;" class="no-print">
    <div class="lang-switcher">
        <?php foreach (I18n::getAvailable() as $code => $label): ?>
        <button class="lang-btn <?= I18n::getLanguage() === $code ? 'active' : '' ?>"
                onclick="switchLanguage('<?= $code ?>')"><?= strtoupper($code) ?></button>
        <?php endforeach; ?>
    </div>
    <button id="darkModeBtn" class="dark-mode-toggle" onclick="toggleDarkMode()" title="Dark Mode">🌙</button>
</div>

<?php if ($currentUser): ?>
<div class="header no-print">
    <div class="header-title">👋 <?= __('hello', ['name' => htmlspecialchars($currentUser['name'])]) ?></div>
    <div class="header-right">
        <?php if ($auth->isAdmin()): ?>
            <a href="admin/" class="btn-header">⚙️ <?= __('nav_administration') ?></a>
        <?php endif; ?>
        <a href="logout.php" class="btn-header"><?= __('btn_logout') ?></a>
    </div>
</div>
<?php elseif ($publicAccess): ?>
<div class="header no-print">
    <div class="header-title"><?= htmlspecialchars($appTitle) ?></div>
    <div class="header-right">
        <a href="login.php" class="btn-header">🔐 <?= __('btn_login') ?></a>
    </div>
</div>
<?php endif; ?>

<div class="container">
    <?php if ($logoUrl && !$voucherCreated && !$bulkCreated): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="logo">
    <?php endif; ?>

    <?php if (!$voucherCreated && !$bulkCreated): ?>
    <h1><?= htmlspecialchars($appTitle) ?></h1>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($voucherCreated): ?>
        <div id="printArea">
            <?= buildPrintCard($printTemplate, $voucherData, $instructionHeader, $instructionText, $appTitle) ?>
        </div>

        <div class="voucher-result no-print">
            <div style="font-size:18px;margin-bottom:10px;"><?= __('voucher_success_title') ?></div>
            <div class="voucher-code" id="voucherCode" onclick="copyCode()" title="Klicken zum Kopieren">
                <?= htmlspecialchars($voucherCode) ?>
            </div>
            <div class="voucher-info">
                <?= str_replace('{minutes}', $voucherData['expire_min'], __('voucher_validity')) ?>
            </div>
            <div class="qr-wrapper no-print">
                <div id="qrcode"></div>
                <div class="qr-label"><?= __('voucher_qr_label') ?></div>
            </div>
        </div>

        <?php if ($instructionHeader || $instructionText): ?>
        <div class="instruction-box no-print">
            <?php if ($instructionHeader): ?><h3><?= htmlspecialchars($instructionHeader) ?></h3><?php endif; ?>
            <?php if ($instructionText): ?><div><?= $instructionText ?></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="get" class="no-print" style="margin-top:15px;">
            <button type="submit" class="btn"><?= __('btn_new_code') ?></button>
        </form>
        <button onclick="window.print()" class="btn btn-outline no-print" style="margin-top:10px;">
            🖨️ <?= __('voucher_print_btn') ?>
        </button>

    <?php elseif ($bulkCreated): ?>
        <h1 style="font-size:22px;margin-bottom:20px;"><?= str_replace('{count}', count($bulkVouchers), __('bulk_results')) ?></h1>

        <div id="printArea">
            <?php foreach ($bulkVouchers as $idx => $bv): ?>
            <div class="<?= $idx > 0 ? 'print-page-break' : '' ?>">
                <?= buildPrintCard($printTemplate, $bv, $instructionHeader, $instructionText, $appTitle) ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="no-print">
            <table class="bulk-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= __('label_code') ?></th>
                        <th><?= __('label_site') ?></th>
                        <th><?= __('label_expires') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bulkVouchers as $i => $bv): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <code onclick="copyToClipboard('<?= addslashes($bv['code']) ?>', 'Kopiert!')"
                                  title="Klicken zum Kopieren"><?= htmlspecialchars($bv['code']) ?></code>
                        </td>
                        <td><?= htmlspecialchars($bv['site_name']) ?></td>
                        <td><?= $bv['expiry_date'] ?> <?= $bv['expiry_time'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="copy-hint" style="margin-top:8px;">Code anklicken zum Kopieren</p>
        </div>

        <div class="no-print" style="display:flex;gap:10px;margin-top:20px;">
            <button onclick="window.print()" class="btn" style="flex:1;">
                🖨️ <?= __('bulk_print_all') ?>
            </button>
            <form method="get" style="flex:1;">
                <button type="submit" class="btn btn-outline" style="width:100%;"><?= __('btn_new_code') ?></button>
            </form>
        </div>

    <?php elseif (empty($sites)): ?>
        <div class="empty-state">
            <div style="font-size:60px;margin-bottom:20px;opacity:0.3;">📶</div>
            <p><?= __('voucher_no_sites') ?><br>
            <?php if ($auth->isAdmin()): ?>
                <a href="admin/" style="color:var(--accent);"><?= __('voucher_no_sites_admin') ?></a>
            <?php else: ?>
                <?= __('voucher_no_sites_user') ?>
            <?php endif; ?>
            </p>
        </div>

    <?php else: ?>

        <?php if ($auth->isLoggedIn()): ?>
        <div class="mode-tabs no-print">
            <button class="mode-tab active" id="tab-single" onclick="switchMode('single')"><?= __('bulk_tab') ?></button>
            <button class="mode-tab" id="tab-bulk" onclick="switchMode('bulk')"><?= __('bulk_tab_bulk') ?></button>
        </div>
        <?php endif; ?>

        <?php if (!empty($templates)): ?>
        <div class="template-dropdown">
            <label for="template_select"><?= __('voucher_template_label') ?></label>
            <select id="template_select" onchange="applyTemplate(this)">
                <option value=""><?= __('voucher_template_select') ?></option>
                <?php foreach ($templates as $tpl): ?>
                <option value="<?= (int)$tpl['id'] ?>"
                        data-max-uses="<?= (int)$tpl['max_uses'] ?>"
                        data-expire="<?= (int)$tpl['expire_minutes'] ?>"
                        data-qos-down="<?= (int)($tpl['qos_rate_max_down'] ?? 0) ?>"
                        data-qos-up="<?= (int)($tpl['qos_rate_max_up'] ?? 0) ?>"
                        data-qos-quota="<?= (int)($tpl['qos_usage_quota'] ?? 0) ?>"
                        data-desc="<?= htmlspecialchars($tpl['description'] ?? '') ?>">
                    <?= htmlspecialchars($tpl['name']) ?> –
                    <?= (int)$tpl['max_uses'] ?> <?= __('label_devices') ?>,
                    <?= (int)$tpl['expire_minutes'] ?> <?= __('minutes_short') ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="template-hint" id="template_desc"></div>
        </div>
        <?php endif; ?>

        <!-- Single voucher form -->
        <div id="mode-single">
            <form method="post" id="voucherForm">
                <input type="hidden" name="create_voucher" value="1">
                <input type="hidden" name="expire_minutes" id="expire_minutes" value="<?= $defaultExpire ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->getCsrfToken()) ?>">
                <input type="hidden" name="qos_down" class="qos-down-field" value="0">
                <input type="hidden" name="qos_up" class="qos-up-field" value="0">
                <input type="hidden" name="qos_quota" class="qos-quota-field" value="0">

                <div class="form-group">
                    <label for="voucher_name"><?= __('voucher_name_label') ?></label>
                    <input type="text" id="voucher_name" name="voucher_name"
                           placeholder="<?= __('voucher_name_hint') ?>" required>
                </div>

                <div class="form-group">
                    <label for="max_uses"><?= __('voucher_devices_label') ?></label>
                    <input type="number" id="max_uses" name="max_uses"
                           min="1" max="<?= $maxUsesLimit ?>" value="<?= $defaultMaxUses ?>" required>
                </div>

                <div class="form-group">
                    <label for="site_id"><?= __('voucher_site_label') ?></label>
                    <select id="site_id" name="site_id" required>
                        <?php if (count($sites) > 1): ?>
                            <option value=""><?= __('voucher_site_select') ?></option>
                        <?php endif; ?>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= (int)$site['id'] ?>" <?= ($autoSelectSite == $site['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($site['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($smtpEnabled): ?>
                <div class="email-option" id="email-option-box">
                    <div class="email-checkbox-wrapper">
                        <input type="checkbox" id="send_email" name="send_email" onchange="toggleEmailField()">
                        <label for="send_email">
                            ✉️ <?= __('voucher_email_send') ?>
                        </label>
                    </div>
                    <div class="email-input-wrapper" id="email_field">
                        <div style="margin-top:12px;">
                            <label for="recipient_email"><?= __('voucher_email_label') ?></label>
                            <input type="email" id="recipient_email" name="recipient_email"
                                   placeholder="<?= __('voucher_email_hint') ?>">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn" id="submitBtn">
                    <?= __('voucher_create_btn') ?>
                </button>
            </form>
        </div>

        <!-- Bulk creation form -->
        <div id="mode-bulk" style="display:none;">
            <form method="post" id="bulkForm">
                <input type="hidden" name="create_bulk" value="1">
                <input type="hidden" name="expire_minutes" id="bulk_expire_minutes" value="<?= $defaultExpire ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->getCsrfToken()) ?>">
                <input type="hidden" name="qos_down" class="qos-down-field" value="0">
                <input type="hidden" name="qos_up" class="qos-up-field" value="0">
                <input type="hidden" name="qos_quota" class="qos-quota-field" value="0">

                <div class="form-group">
                    <label for="bulk_count"><?= __('bulk_quantity') ?></label>
                    <input type="number" id="bulk_count" name="bulk_count"
                           min="1" max="20" value="5" required>
                    <p style="font-size:12px;color:var(--text-muted);margin-top:5px;"><?= __('bulk_quantity_hint') ?></p>
                </div>

                <div class="form-group">
                    <label for="bulk_voucher_name"><?= __('bulk_name_prefix') ?></label>
                    <input type="text" id="bulk_voucher_name" name="voucher_name"
                           placeholder="<?= __('voucher_name_hint') ?>" required>
                </div>

                <div class="form-group">
                    <label for="bulk_max_uses"><?= __('voucher_devices_label') ?></label>
                    <input type="number" id="bulk_max_uses" name="max_uses"
                           min="1" max="<?= $maxUsesLimit ?>" value="<?= $defaultMaxUses ?>" required>
                </div>

                <div class="form-group">
                    <label for="bulk_site_id"><?= __('voucher_site_label') ?></label>
                    <select id="bulk_site_id" name="site_id" required>
                        <?php if (count($sites) > 1): ?>
                            <option value=""><?= __('voucher_site_select') ?></option>
                        <?php endif; ?>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= (int)$site['id'] ?>" <?= ($autoSelectSite == $site['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($site['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn" id="bulkSubmitBtn">
                    <?= str_replace('{count}', '<span id="bulkCountLabel">5</span>', __('bulk_create_btn')) ?>
                </button>
            </form>
        </div>

        <?php if ($instructionHeader || $instructionText): ?>
        <div class="instruction-box" style="margin-top:25px;">
            <?php if ($instructionHeader): ?><h3><?= htmlspecialchars($instructionHeader) ?></h3><?php endif; ?>
            <?php if ($instructionText): ?><div><?= $instructionText ?></div><?php endif; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<div id="toast-container"></div>
<script src="assets/global.js"></script>
<script>
    <?php if ($voucherCreated): ?>
    document.addEventListener('DOMContentLoaded', function() {
        new QRCode(document.getElementById('qrcode'), {
            text: '<?= addslashes($voucherCode) ?>',
            width: 160, height: 160,
            colorDark: '#ffffff', colorLight: 'transparent',
            correctLevel: QRCode.CorrectLevel.M
        });
    });

    function copyCode() {
        copyToClipboard('<?= addslashes($voucherCode) ?>', 'Code kopiert!');
    }
    <?php endif; ?>

    function toggleEmailField() {
        const cb = document.getElementById('send_email');
        const field = document.getElementById('email_field');
        const box = document.getElementById('email-option-box');
        const input = document.getElementById('recipient_email');
        if (!cb || !field) return;
        if (cb.checked) {
            field.classList.add('show');
            box.classList.add('active');
            input.required = true;
            setTimeout(() => input.focus(), 300);
        } else {
            field.classList.remove('show');
            box.classList.remove('active');
            input.required = false;
        }
    }

    function switchMode(mode) {
        document.getElementById('mode-single').style.display = mode === 'single' ? '' : 'none';
        document.getElementById('mode-bulk').style.display   = mode === 'bulk' ? '' : 'none';
        document.getElementById('tab-single').classList.toggle('active', mode === 'single');
        document.getElementById('tab-bulk').classList.toggle('active', mode === 'bulk');
    }

    function applyTemplate(select) {
        const opt = select.selectedOptions[0];
        const expire = opt.value ? parseInt(opt.dataset.expire) : <?= $defaultExpire ?>;
        const maxUses = opt.value ? parseInt(opt.dataset.maxUses) : <?= $defaultMaxUses ?>;

        document.getElementById('expire_minutes').value = expire;
        document.getElementById('bulk_expire_minutes').value = expire;
        const muEl = document.getElementById('max_uses');
        if (muEl) muEl.value = maxUses;
        const bmuEl = document.getElementById('bulk_max_uses');
        if (bmuEl) bmuEl.value = maxUses;

        const qosDown  = opt.value ? (parseInt(opt.dataset.qosDown)  || 0) : 0;
        const qosUp    = opt.value ? (parseInt(opt.dataset.qosUp)    || 0) : 0;
        const qosQuota = opt.value ? (parseInt(opt.dataset.qosQuota) || 0) : 0;
        document.querySelectorAll('.qos-down-field').forEach(el => el.value = qosDown);
        document.querySelectorAll('.qos-up-field').forEach(el => el.value = qosUp);
        document.querySelectorAll('.qos-quota-field').forEach(el => el.value = qosQuota);

        const descEl = document.getElementById('template_desc');
        if (descEl) descEl.textContent = opt.dataset.desc || '';
    }

    // Bulk count label sync
    const bulkCountInput = document.getElementById('bulk_count');
    const bulkCountLabel = document.getElementById('bulkCountLabel');
    if (bulkCountInput && bulkCountLabel) {
        bulkCountInput.addEventListener('input', function() {
            bulkCountLabel.textContent = this.value;
        });
    }

    // Form submit loading states
    document.getElementById('voucherForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        if (!btn || btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        btn.innerHTML = '⏳ <?= __('voucher_creating') ?>';
    });

    document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('bulkSubmitBtn');
        if (!btn || btn.disabled) { e.preventDefault(); return; }
        const count = document.getElementById('bulk_count').value;
        btn.disabled = true;
        btn.innerHTML = '⏳ <?= addslashes(str_replace('{count}', "' + count + '", __('bulk_creating'))) ?>';
    });

    document.addEventListener('DOMContentLoaded', function() {
        toggleEmailField?.();
    });
</script>
</body>
</html>
