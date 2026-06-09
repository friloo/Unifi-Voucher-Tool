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
require_once __DIR__ . '/includes/Helpers.php';

$auth = new Auth();
$db = Database::getInstance();
$mailer = new Mailer();
I18n::init();

/**
 * Throttle fuer die anonyme oeffentliche Voucher-Erstellung:
 * max. 10 Voucher in 10 Minuten. Primaer IP-basiert ueber die Tabelle
 * request_throttle (laesst sich nicht per Cookie-Loeschen umgehen);
 * Fallback auf den Session-Zaehler, falls die Tabelle auf einer alten
 * Installation noch fehlt (Migration 0002 nicht gelaufen).
 */
function isVoucherRateLimited($db, $voucherCount = 1) {
    $window = 600;       // 10 Minuten
    $maxVouchers = 10;

    $limited = throttleHit($db, 'voucher_create', $maxVouchers, 10, $voucherCount);
    if ($limited !== null) {
        return $limited;
    }
    // Tabelle existiert noch nicht (Migration 0002 nicht gelaufen)
    // -> Session-Fallback (Legacy-Verhalten)
    $now = time();
    $timestamps = $_SESSION['voucher_create_times'] ?? [];
    $timestamps = array_values(array_filter($timestamps, function ($t) use ($now, $window) {
        return ($now - $t) < $window;
    }));
    if (count($timestamps) + $voucherCount > $maxVouchers) {
        $_SESSION['voucher_create_times'] = $timestamps;
        return true;
    }
    for ($i = 0; $i < $voucherCount; $i++) {
        $timestamps[] = $now;
    }
    $_SESSION['voucher_create_times'] = $timestamps;
    return false;
}

/**
 * Validiert die Voucher-Gueltigkeit (Minuten). Anonyme Nutzer duerfen nur den
 * konfigurierten Default oder Werte aktiver Templates verwenden – das Feld ist
 * ein Hidden-Input und damit beliebig manipulierbar. Eingeloggte Nutzer werden
 * auf maximal 1 Jahr begrenzt.
 */
function sanitizeExpireMinutes($expireMinutes, $isLoggedIn, $templates, $defaultExpire) {
    $expireMinutes = (int)$expireMinutes;
    if ($isLoggedIn) {
        return max(1, min(525600, $expireMinutes));
    }
    $allowed = array_map(function ($t) { return (int)$t['expire_minutes']; }, $templates);
    $allowed[] = $defaultExpire;
    return in_array($expireMinutes, $allowed, true) ? $expireMinutes : $defaultExpire;
}

/** Minuten menschenlesbar formatieren (z.B. 480 -> "8 Stunden"). */
function formatDuration($minutes) {
    $minutes = (int)$minutes;
    if ($minutes >= 1440 && $minutes % 1440 === 0) {
        $days = $minutes / 1440;
        return $days === 1 ? __('dur_day_one') : __('dur_days', ['n' => $days]);
    }
    if ($minutes >= 60 && $minutes % 60 === 0) {
        $hours = $minutes / 60;
        return $hours === 1 ? __('dur_hour_one') : __('dur_hours', ['n' => $hours]);
    }
    return __('dur_minutes', ['n' => $minutes]);
}

$appTitle          = $db->getSetting('app_title', 'UniFi Voucher System');
$logoUrl           = $db->getSetting('logo_url', '');
$faviconUrl        = $db->getSetting('favicon_url', '');
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
function doCreateVoucher($db, $site, $voucherName, $maxUses, $expireMinutes, $userId) {
    $datum          = date('Y-m-d');
    $fullName       = $datum . '_' . $voucherName;
    $controller     = new UniFiController(
        $site['unifi_controller_url'],
        $site['unifi_username'],
        Crypto::decrypt($site['unifi_password']),
        $site['site_id']
    );
    $voucher = $controller->createVoucher($fullName, $maxUses, $expireMinutes);
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
    } elseif (!$auth->isLoggedIn() && isVoucherRateLimited($db)) {
        $error = __('error_rate_limited');
    } else {
        try {
            $siteId        = (int)($_POST['site_id'] ?? 0);
            $voucherName   = trim((string)($_POST['voucher_name'] ?? ''));
            $maxUses       = (int)($_POST['max_uses'] ?? $defaultMaxUses);
            $expireMinutes = sanitizeExpireMinutes($_POST['expire_minutes'] ?? $defaultExpire, $auth->isLoggedIn(), $templates, $defaultExpire);
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
            $voucherData = doCreateVoucher($db, $site, $voucherName, $maxUses, $expireMinutes, $userId);
            $voucherCode  = $voucherData['code'];
            $voucherCreated = true;

            if ($sendEmail && !empty($recipientEmail)) {
                $mailer->sendVoucherEmail($recipientEmail, $voucherCode, $site['name'], $maxUses);
                $success = __('voucher_created_mail');
            } else {
                $success = __('voucher_created_ok');
            }

            $auth->writeAuditLog($userId, 'voucher_create', 'voucher', null,
                "Voucher '{$voucherName}' für {$site['name']}" . ($userId === null ? ' (öffentlich)' : ''));

            // PRG-Pattern: Redirect nach erfolgreichem POST, damit ein Reload
            // (F5) keinen Duplikat-Voucher erzeugt. Ergebnis via Session-Flash.
            $_SESSION['voucher_flash'] = ['type' => 'single', 'data' => $voucherData, 'success' => $success];
            header('Location: index.php?created=1');
            exit;
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// Bulk voucher creation – nur fuer eingeloggte Nutzer. Das Formular wird
// Anonymen zwar nicht angezeigt, der POST-Endpunkt muss es aber ebenfalls
// serverseitig erzwingen (sonst 20 Voucher pro Request im Public-Modus).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_bulk'])) {
    if (!$auth->isLoggedIn()) {
        $error = __('error_login_req');
    } elseif (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('error_csrf');
    } else {
        try {
            $siteId        = (int)($_POST['site_id'] ?? 0);
            $voucherName   = trim((string)($_POST['voucher_name'] ?? ''));
            $maxUses       = (int)($_POST['max_uses'] ?? $defaultMaxUses);
            $expireMinutes = sanitizeExpireMinutes($_POST['expire_minutes'] ?? $defaultExpire, true, $templates, $defaultExpire);
            $bulkCount     = max(1, min(20, (int)($_POST['bulk_count'] ?? 1)));

            if (empty($voucherName)) throw new Exception(__('error_name_req'));
            if ($maxUses < 1 || $maxUses > $maxUsesLimit) throw new Exception(__('error_devices_range', ['max' => $maxUsesLimit]));
            if ($siteId <= 0) throw new Exception(__('error_site_req'));
            if (!$auth->hasAccessToSite($siteId)) throw new Exception(__('error_site_no_perm'));

            $site = $db->fetchOne("SELECT * FROM sites WHERE id = ? AND is_active = 1", [$siteId]);
            if (!$site) throw new Exception(__('error_site_not_found'));

            $userId = $_SESSION['user_id'] ?? null;

            // Alle Voucher in EINEM UniFi-API-Call erstellen ('n'-Parameter)
            // statt pro Voucher Login + Voucherliste abzurufen.
            $fullName   = date('Y-m-d') . '_' . $voucherName;
            $controller = new UniFiController(
                $site['unifi_controller_url'],
                $site['unifi_username'],
                Crypto::decrypt($site['unifi_password']),
                $site['site_id']
            );
            $created  = $controller->createVouchers($fullName, $maxUses, $expireMinutes, $bulkCount);
            $expiryTs = time() + ($expireMinutes * 60);

            foreach ($created as $i => $voucher) {
                $db->execute(
                    "INSERT INTO vouchers (site_id, user_id, voucher_code, voucher_name, max_uses, expire_minutes, unifi_voucher_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$site['id'], $userId, $voucher['code'], $fullName . '_' . ($i + 1), $maxUses, $expireMinutes, $voucher['unifi_id'] ?? null]
                );
                $bulkVouchers[] = [
                    'code'        => $voucher['formatted_code'],
                    'site_name'   => $site['name'],
                    'max_uses'    => $maxUses,
                    'expire_min'  => $expireMinutes,
                    'expiry_date' => date('d.m.Y', $expiryTs),
                    'expiry_time' => date('H:i', $expiryTs),
                ];
            }

            $auth->writeAuditLog($userId, 'voucher_bulk', 'voucher', null,
                count($created) . " Vouchers '{$voucherName}' für {$site['name']}");

            $success = str_replace('{count}', count($created), __('bulk_success'));

            // PRG-Pattern: Reload darf die Bulk-Erstellung nicht wiederholen.
            $_SESSION['voucher_flash'] = ['type' => 'bulk', 'data' => $bulkVouchers, 'success' => $success];
            header('Location: index.php?created=1');
            exit;
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// PRG: Ergebnis nach Redirect aus dem Session-Flash wiederherstellen.
// Der Flash bleibt fuer Reloads der Ergebnisseite erhalten und wird beim
// Zurueckkehren zum Formular (GET ohne ?created) verworfen.
if (isset($_GET['created']) && !empty($_SESSION['voucher_flash'])) {
    $flash = $_SESSION['voucher_flash'];
    if (($flash['type'] ?? '') === 'bulk') {
        $bulkVouchers = $flash['data'];
        $bulkCreated  = true;
    } else {
        $voucherData    = $flash['data'];
        $voucherCode    = $voucherData['code'];
        $voucherCreated = true;
    }
    $success = $flash['success'] ?? '';
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['voucher_flash']);
}

$currentUser = $auth->isLoggedIn() ? $auth->getCurrentUser() : null;

// Bei Validierungsfehlern: eingegebene Werte und aktiven Tab erhalten
$activeMode      = ($error && isset($_POST['create_bulk'])) ? 'bulk' : 'single';
$stickyName      = $error ? trim((string)($_POST['voucher_name'] ?? '')) : '';
$stickyMaxUses   = $error ? (int)($_POST['max_uses'] ?? $defaultMaxUses) : $defaultMaxUses;
$stickyBulkCount = $error ? max(1, min(20, (int)($_POST['bulk_count'] ?? 5))) : 5;
$stickySiteId    = $error ? (int)($_POST['site_id'] ?? 0) : 0;
if ($stickyMaxUses < 1 || $stickyMaxUses > $maxUsesLimit) $stickyMaxUses = $defaultMaxUses;
// Anonyme Gaeste wissen oft nicht, was sie als Namen eintragen sollen -> Default
if ($stickyName === '' && !$auth->isLoggedIn()) $stickyName = __('voucher_name_default');

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
    <?php if ($faviconUrl): ?>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
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
            <div class="voucher-code" id="voucherCode" onclick="copyCode()" title="<?= __('click_to_copy') ?>">
                <?= htmlspecialchars($voucherCode) ?>
            </div>
            <div class="voucher-info">
                <?= str_replace('{duration}', formatDuration($voucherData['expire_min']), __('voucher_validity')) ?>
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
                            <code onclick="copyToClipboard('<?= addslashes($bv['code']) ?>', '<?= addslashes(__('toast_copied')) ?>')"
                                  title="<?= __('click_to_copy') ?>"><?= htmlspecialchars($bv['code']) ?></code>
                        </td>
                        <td><?= htmlspecialchars($bv['site_name']) ?></td>
                        <td><?= $bv['expiry_date'] ?> <?= $bv['expiry_time'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="copy-hint" style="margin-top:8px;"><?= __('copy_hint') ?></p>
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

                <div class="form-group">
                    <label for="voucher_name"><?= __('voucher_name_label') ?></label>
                    <input type="text" id="voucher_name" name="voucher_name"
                           value="<?= htmlspecialchars($stickyName) ?>"
                           placeholder="<?= __('voucher_name_hint') ?>" required>
                </div>

                <div class="form-group">
                    <label for="max_uses"><?= __('voucher_devices_label') ?></label>
                    <input type="number" id="max_uses" name="max_uses"
                           min="1" max="<?= $maxUsesLimit ?>" value="<?= $stickyMaxUses ?>" required>
                </div>

                <div class="form-group">
                    <label for="site_id"><?= __('voucher_site_label') ?></label>
                    <select id="site_id" name="site_id" required>
                        <?php if (count($sites) > 1): ?>
                            <option value=""><?= __('voucher_site_select') ?></option>
                        <?php endif; ?>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= (int)$site['id'] ?>" <?= (($stickySiteId ?: $autoSelectSite) == $site['id']) ? 'selected' : '' ?>>
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

        <!-- Bulk creation form (nur fuer eingeloggte Nutzer, serverseitig erzwungen) -->
        <?php if ($auth->isLoggedIn()): ?>
        <div id="mode-bulk" style="display:none;">
            <form method="post" id="bulkForm">
                <input type="hidden" name="create_bulk" value="1">
                <input type="hidden" name="expire_minutes" id="bulk_expire_minutes" value="<?= $defaultExpire ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->getCsrfToken()) ?>">

                <div class="form-group">
                    <label for="bulk_count"><?= __('bulk_quantity') ?></label>
                    <input type="number" id="bulk_count" name="bulk_count"
                           min="1" max="20" value="<?= $stickyBulkCount ?>" required>
                    <p style="font-size:12px;color:var(--text-muted);margin-top:5px;"><?= __('bulk_quantity_hint') ?></p>
                </div>

                <div class="form-group">
                    <label for="bulk_voucher_name"><?= __('bulk_name_prefix') ?></label>
                    <input type="text" id="bulk_voucher_name" name="voucher_name"
                           value="<?= $activeMode === 'bulk' ? htmlspecialchars($stickyName) : '' ?>"
                           placeholder="<?= __('voucher_name_hint') ?>" required>
                </div>

                <div class="form-group">
                    <label for="bulk_max_uses"><?= __('voucher_devices_label') ?></label>
                    <input type="number" id="bulk_max_uses" name="max_uses"
                           min="1" max="<?= $maxUsesLimit ?>" value="<?= $stickyMaxUses ?>" required>
                </div>

                <div class="form-group">
                    <label for="bulk_site_id"><?= __('voucher_site_label') ?></label>
                    <select id="bulk_site_id" name="site_id" required>
                        <?php if (count($sites) > 1): ?>
                            <option value=""><?= __('voucher_site_select') ?></option>
                        <?php endif; ?>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= (int)$site['id'] ?>" <?= (($stickySiteId ?: $autoSelectSite) == $site['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($site['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn" id="bulkSubmitBtn">
                    <?= str_replace('{count}', '<span id="bulkCountLabel">' . $stickyBulkCount . '</span>', __('bulk_create_btn')) ?>
                </button>
            </form>
        </div>
        <?php endif; ?>

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
        // Dunkle Module auf weissem Grund: invertierte QR-Codes (hell auf
        // dunkel) werden von vielen Kamera-Apps nicht erkannt.
        new QRCode(document.getElementById('qrcode'), {
            text: '<?= addslashes($voucherCode) ?>',
            width: 160, height: 160,
            colorDark: '#000000', colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    });

    function copyCode() {
        copyToClipboard('<?= addslashes($voucherCode) ?>', '<?= addslashes(__('toast_copied')) ?>');
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
        const single = document.getElementById('mode-single');
        const bulk   = document.getElementById('mode-bulk');
        if (!single || !bulk) return; // Bulk existiert nur fuer eingeloggte Nutzer
        single.style.display = mode === 'single' ? '' : 'none';
        bulk.style.display   = mode === 'bulk' ? '' : 'none';
        document.getElementById('tab-single').classList.toggle('active', mode === 'single');
        document.getElementById('tab-bulk').classList.toggle('active', mode === 'bulk');
    }

    function applyTemplate(select) {
        const opt = select.selectedOptions[0];
        const expire = opt.value ? parseInt(opt.dataset.expire) : <?= $defaultExpire ?>;
        const maxUses = opt.value ? parseInt(opt.dataset.maxUses) : <?= $defaultMaxUses ?>;

        const expEl = document.getElementById('expire_minutes');
        if (expEl) expEl.value = expire;
        const bexpEl = document.getElementById('bulk_expire_minutes');
        if (bexpEl) bexpEl.value = expire;
        const muEl = document.getElementById('max_uses');
        if (muEl) muEl.value = maxUses;
        const bmuEl = document.getElementById('bulk_max_uses');
        if (bmuEl) bmuEl.value = maxUses;

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
        <?php if ($activeMode === 'bulk'): ?>
        switchMode('bulk'); // Nach Fehler im Bulk-Formular im Bulk-Tab bleiben
        <?php endif; ?>
    });
</script>
</body>
</html>
