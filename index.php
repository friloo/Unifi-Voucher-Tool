<?php
// Error Reporting für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/UniFiController.php';
require_once __DIR__ . '/includes/Mailer.php';

$auth = new Auth();
$db = Database::getInstance();
$mailer = new Mailer();

// Settings laden
$appTitle = $db->getSetting('app_title', 'UniFi Voucher System');
$logoUrl = $db->getSetting('logo_url', '');
$instructionHeader = $db->getSetting('instruction_header', 'So verwenden Sie Ihren Code');
$instructionText = $db->getSetting('instruction_text', '');
$publicAccess = $db->getSetting('public_access', 0);
$printTemplate = $db->getSetting('print_template', '<div style="text-align: center; padding: 40px;">
    <h1>{APP_TITLE}</h1>
    <h2>WLAN Zugangscode</h2>
    <div style="font-size: 48px; font-weight: bold; margin: 30px 0; font-family: monospace; letter-spacing: 4px;">{VOUCHER_CODE}</div>
    <p><strong>Gültig bis:</strong> {EXPIRY_DATE} um {EXPIRY_TIME} Uhr</p>
    <p><strong>Standort:</strong> {SITE_NAME}</p>
    <p><strong>Maximale Geräte:</strong> {MAX_USES}</p>
    <hr style="margin: 30px 0;">
    <div style="font-size: 14px; text-align: left;">
        {INSTRUCTIONS}
    </div>
</div>');

// Prüfen ob Login erforderlich - aber nur wenn nicht auf login.php
if (!$publicAccess && !$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$voucherCode = '';
$voucherCreated = false;
$voucherData = [];

// Sites abrufen
if ($auth->isLoggedIn()) {
    if ($auth->isAdmin()) {
        $sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1 ORDER BY name");
    } else {
        $sites = $db->fetchAll(
            "SELECT s.* FROM sites s
             INNER JOIN user_site_access usa ON s.id = usa.site_id
             WHERE s.is_active = 1 AND usa.user_id = ?
             ORDER BY s.name",
            [$_SESSION['user_id']]
        );
    }
} else {
    // Öffentlicher Zugriff - nur Sites mit public_access
    $sites = $db->fetchAll("SELECT * FROM sites WHERE is_active = 1 AND public_access = 1 ORDER BY name");
}

// Voucher erstellen
// WICHTIG: create_voucher kommt jetzt aus einem hidden input, nicht vom Button (disabled Buttons werden teils nicht gesendet)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_voucher'])) {
    if (!$publicAccess && !$auth->isLoggedIn()) {
        $error = 'Sie müssen angemeldet sein';
    } elseif ($auth->isLoggedIn() && !$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiges Sicherheits-Token';
    } else {
        try {
            $siteId = (int)($_POST['site_id'] ?? 0);
            $voucherName = trim((string)($_POST['voucher_name'] ?? ''));
            $maxUses = (int)($_POST['max_uses'] ?? 0);
            $sendEmail = isset($_POST['send_email']) && !empty($_POST['recipient_email']);
            $recipientEmail = trim((string)($_POST['recipient_email'] ?? ''));

            // Validierung
            if (empty($voucherName)) {
                throw new Exception('Bitte geben Sie einen Voucher-Namen ein');
            }

            if ($maxUses < 1 || $maxUses > 10) {
                throw new Exception('Anzahl der Geräte muss zwischen 1 und 10 liegen');
            }

            if ($siteId <= 0) {
                throw new Exception('Bitte wählen Sie einen Standort');
            }

            if ($sendEmail && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Ungültige E-Mail-Adresse');
            }

            // Site-Zugriff prüfen
            if ($auth->isLoggedIn() && !$auth->hasAccessToSite($siteId)) {
                throw new Exception('Keine Berechtigung für diese Site');
            }

            // Site-Daten abrufen
            $site = $db->fetchOne("SELECT * FROM sites WHERE id = ? AND is_active = 1", [$siteId]);

            if (!$site) {
                throw new Exception('Site nicht gefunden');
            }

            // Voucher-Namen formatieren
            $datum = date('Y-m-d');
            $fullVoucherName = $datum . '_' . $voucherName;

            // UniFi Controller initialisieren
            $controller = new UniFiController(
                $site['unifi_controller_url'],
                $site['unifi_username'],
                $site['unifi_password'],
                $site['site_id']
            );

            // Voucher erstellen
            $voucher = $controller->createVoucher($fullVoucherName, $maxUses, 480);

            if (!is_array($voucher) || empty($voucher['formatted_code']) || empty($voucher['code'])) {
                throw new Exception('UniFi hat keinen gültigen Voucher zurückgegeben');
            }

            $voucherCode = $voucher['formatted_code'];

            // In Datenbank speichern
            $userId = $auth->isLoggedIn() ? ($_SESSION['user_id'] ?? null) : null;
            $db->execute(
                "INSERT INTO vouchers (site_id, user_id, voucher_code, voucher_name, max_uses, expire_minutes, unifi_voucher_id)
                 VALUES (?, ?, ?, ?, ?, 480, ?)",
                [$siteId, $userId, $voucher['code'], $fullVoucherName, $maxUses, ($voucher['unifi_id'] ?? null)]
            );

            // Voucher-Daten für Druck speichern
            $expiryTimestamp = time() + (480 * 60);
            $voucherData = [
                'code' => $voucherCode,
                'site_name' => $site['name'],
                'max_uses' => $maxUses,
                'expiry_date' => date('d.m.Y', $expiryTimestamp),
                'expiry_time' => date('H:i', $expiryTimestamp)
            ];

            // E-Mail versenden falls gewünscht
            if ($sendEmail && !empty($recipientEmail)) {
                if ($mailer->sendVoucherEmail($recipientEmail, $voucherCode, $site['name'], $maxUses)) {
                    $success .= ' E-Mail wurde erfolgreich versendet!';
                } else {
                    $success .= ' (E-Mail konnte nicht versendet werden)';
                }
            }

            $voucherCreated = true;
            $success = 'Voucher erfolgreich erstellt!' . ($sendEmail ? ' E-Mail wurde versendet.' : '');
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

$currentUser = $auth->isLoggedIn() ? $auth->getCurrentUser() : null;

// Auto-select wenn nur eine Site
$autoSelectSite = (count($sites) === 1) ? $sites[0]['id'] : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appTitle) ?></title>
    <?php if ($voucherCreated): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSe2keRB6Q5pBUtIxCY7bQMsVB0ANBpd6JDg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .header {
            max-width: 1200px;
            margin: 0 auto 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            padding: 15px 25px;
            border-radius: 15px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .header-logo { max-height: 40px; }
        .header-title {
            color: white;
            font-size: 20px;
            font-weight: 600;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-info { color: white; font-size: 14px; }
        .btn-header {
            background: white;
            color: #667eea;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        .btn-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
        }
        .logo {
            max-width: 250px;
            display: block;
            margin: 0 auto 30px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 28px;
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
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        input[type="text"],
        input[type="number"],
        input[type="email"],
        select {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn {
            width: 100%;
            padding: 16px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        .voucher-result {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 25px;
        }
        .voucher-code {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 2px;
            margin: 20px 0;
            font-family: 'Courier New', monospace;
        }
        .voucher-info {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 15px;
        }
        .instruction-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 25px;
        }
        .instruction-box h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .instruction-box p {
            color: #666;
            line-height: 1.6;
            font-size: 14px;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        /* Verbessertes E-Mail Option Design */
        .email-option {
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            transition: all 0.3s ease;
        }
        .email-option.active {
            background: linear-gradient(135deg, #e7f3ff 0%, #f0e7ff 100%);
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        .email-checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            margin-bottom: 0;
        }
        .email-checkbox-wrapper input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            margin: 0;
            accent-color: #667eea;
        }
        .email-checkbox-wrapper label {
            margin: 0;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .email-input-wrapper {
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transition: all 0.3s ease;
            margin-top: 0;
        }
        .email-input-wrapper.show {
            max-height: 200px;
            opacity: 1;
            margin-top: 15px;
        }
        .email-input-wrapper input {
            background: white;
            border: 2px solid #e0e0e0;
        }
        .email-input-wrapper input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .print-button {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .print-button:hover {
            background: #667eea;
            color: white;
        }
        .print-button svg {
            width: 20px;
            height: 20px;
        }
        .qr-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 20px 0 0;
        }
        .qr-wrapper canvas, .qr-wrapper img {
            border: 6px solid white;
            border-radius: 8px;
        }
        .qr-label {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 8px;
        }

        /* Print Styles */
        @media print {
            body * { visibility: hidden; }
            #printArea, #printArea * { visibility: visible; }
            #printArea {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                background: white;
            }
            .btn, .header, form, .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <?php if ($currentUser): ?>
    <div class="header no-print">
        <div class="header-left">
            <div class="header-title">👋 Hallo, <?= htmlspecialchars($currentUser['name']) ?></div>
        </div>
        <div class="header-right">
            <?php if ($auth->isAdmin()): ?>
                <a href="admin/" class="btn-header">⚙️ Administration</a>
            <?php endif; ?>
            <a href="logout.php" class="btn-header">Abmelden</a>
        </div>
    </div>
    <?php elseif ($publicAccess): ?>
    <div class="header no-print">
        <div class="header-left">
            <div class="header-title"><?= htmlspecialchars($appTitle) ?></div>
        </div>
        <div class="header-right">
            <a href="login.php" class="btn-header">
                <span style="margin-right: 5px;">🔐</span> Anmelden
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="container">
        <?php if ($logoUrl && !$voucherCreated): ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="logo">
        <?php endif; ?>

        <h1><?= htmlspecialchars($appTitle) ?></h1>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($voucherCreated): ?>
            <div id="printArea">
                <div class="voucher-result">
                    <div style="font-size: 18px; margin-bottom: 10px;">✓ Ihr Zugangs-Code</div>
                    <div class="voucher-code"><?= htmlspecialchars($voucherCode) ?></div>
                    <div class="voucher-info">
                        Der Code ist 8 Stunden ab Erstellung gültig
                    </div>
                    <div class="qr-wrapper no-print">
                        <div id="qrcode"></div>
                        <div class="qr-label">QR-Code scannen zum Verbinden</div>
                    </div>
                </div>

                <?php if ($instructionHeader || $instructionText): ?>
                <div class="instruction-box">
                    <?php if ($instructionHeader): ?>
                        <h3><?= htmlspecialchars($instructionHeader) ?></h3>
                    <?php endif; ?>
                    <?php if ($instructionText): ?>
                        <div><?= $instructionText ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <form method="get" class="no-print">
                <button type="submit" class="btn">Weiteren Code erstellen</button>
            </form>

            <button onclick="window.print()" class="btn print-button no-print">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Code ausdrucken
            </button>

        <?php elseif (empty($sites)): ?>
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <p>Keine verfügbaren Sites gefunden.<br>
                <?php if ($auth->isAdmin()): ?>
                    <a href="admin/" style="color: #667eea;">Klicken Sie hier, um Sites anzulegen</a>
                <?php else: ?>
                    Bitte kontaktieren Sie Ihren Administrator.
                <?php endif; ?>
                </p>
            </div>

        <?php else: ?>
            <form method="post" id="voucherForm">
                <!-- FIX: Trigger-Feld kommt nicht mehr vom Submit-Button -->
                <input type="hidden" name="create_voucher" value="1">

                <?php if ($auth->isLoggedIn()): ?>
                    <input type="hidden" name="csrf_token" value="<?= $auth->getCsrfToken() ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="voucher_name">Voucher-Name *</label>
                    <input type="text" id="voucher_name" name="voucher_name"
                           placeholder="z.B. Besprechung UL, Vertretername Firma XY" required>
                </div>

                <div class="form-group">
                    <label for="max_uses">Wie viele Geräte dürfen sich einloggen? *</label>
                    <input type="number" id="max_uses" name="max_uses"
                           min="1" max="10" value="1" required>
                </div>

                <div class="form-group">
                    <label for="site_id">Standort *</label>
                    <select id="site_id" name="site_id" required>
                        <?php if (count($sites) > 1): ?>
                            <option value="">Bitte wählen...</option>
                        <?php endif; ?>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= (int)$site['id'] ?>" <?= ($autoSelectSite == $site['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($site['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="email-option" id="email-option-box">
                    <div class="email-checkbox-wrapper">
                        <input type="checkbox" id="send_email" name="send_email" onchange="toggleEmailField()">
                        <label for="send_email">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            Code per E-Mail versenden
                        </label>
                    </div>

                    <div class="email-input-wrapper" id="email_field">
                        <label for="recipient_email">E-Mail-Adresse des Empfängers</label>
                        <input type="email" id="recipient_email" name="recipient_email" placeholder="gast@example.com">
                    </div>
                </div>

                <!-- Button ohne name=create_voucher, damit disabled keinen Einfluss hat -->
                <button type="submit" class="btn" id="submitBtn">
                    Voucher erstellen
                </button>
            </form>

            <?php if ($instructionHeader || $instructionText): ?>
            <div class="instruction-box">
                <?php if ($instructionHeader): ?>
                    <h3><?= htmlspecialchars($instructionHeader) ?></h3>
                <?php endif; ?>
                <?php if ($instructionText): ?>
                    <div><?= $instructionText ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        <?php if ($voucherCreated): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new QRCode(document.getElementById('qrcode'), {
                text: '<?= addslashes($voucherCode) ?>',
                width: 160,
                height: 160,
                colorDark: '#ffffff',
                colorLight: 'transparent',
                correctLevel: QRCode.CorrectLevel.M
            });
        });
        <?php endif; ?>

        function toggleEmailField() {
            const checkbox = document.getElementById('send_email');
            const emailField = document.getElementById('email_field');
            const emailBox = document.getElementById('email-option-box');
            const emailInput = document.getElementById('recipient_email');

            if (!checkbox || !emailField || !emailBox || !emailInput) return;

            if (checkbox.checked) {
                emailField.classList.add('show');
                emailBox.classList.add('active');
                emailInput.required = true;
                setTimeout(() => emailInput.focus(), 300);
            } else {
                emailField.classList.remove('show');
                emailBox.classList.remove('active');
                emailInput.required = false;
            }
        }

        // Initialzustand (z.B. nach Browser-Autofill)
        document.addEventListener('DOMContentLoaded', () => {
            toggleEmailField();
        });

        // Form Submit mit Loading State + Double-Submit-Schutz
        document.getElementById('voucherForm')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('submitBtn');
            if (!btn) return;

            // falls schon disabled: Double-Submit verhindern
            if (btn.disabled) {
                e.preventDefault();
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span style="display: inline-block; animation: spin 1s linear infinite;">⏳</span> Erstelle Voucher...';
        });
    </script>

    <style>
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</body>
</html>
