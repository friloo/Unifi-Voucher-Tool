<?php
require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/Crypto.php';

class Auth {
    private $db;
    
    public function __construct() {
        try {
            $this->db = Database::getInstance();
        } catch (Exception $e) {
            die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
        }
        
        // Session-Konfiguration
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_samesite', 'Lax');
            
            if (!session_start()) {
                die("Session konnte nicht gestartet werden");
            }
        }
    }
    
    /**
     * Ermittelt die echte Client-IP. Hinter einem konfigurierten Trusted-Proxy
     * (Setting `trusted_proxy`, kommaseparierte IP-Liste) wird die erste IP aus
     * X-Forwarded-For verwendet, sonst REMOTE_ADDR. Verhindert, dass ein
     * Reverse-Proxy alle Clients als dieselbe IP erscheinen laesst (Rate-Limit).
     */
    public function clientIp() {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $trusted = (string)$this->db->getSetting('trusted_proxy', '');
        } catch (\Exception $e) {
            $trusted = '';
        }
        if ($trusted === '' || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }
        $trustedList = array_filter(array_map('trim', explode(',', $trusted)));
        if (!in_array($remote, $trustedList, true)) {
            return $remote; // Anfrage kam nicht vom Trusted-Proxy -> XFF ignorieren
        }
        $parts = array_filter(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
        $client = $parts[0] ?? $remote;
        return filter_var($client, FILTER_VALIDATE_IP) ? $client : $remote;
    }

    // Benutzer einloggen
    public function login($email, $password) {
        $ip = $this->clientIp();

        if ($this->isRateLimited($ip, $email)) {
            return 'rate_limited';
        }

        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE email = ? AND is_active = 1",
            [$email]
        );

        if ($user && password_verify($password, $user['password_hash'])) {
            $this->clearLoginAttempts($ip, $email);

            // 2FA aktiv? Dann Login zunaechst nur "vormerken" und Code anfordern.
            if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
                $_SESSION['totp_pending_user_id'] = $user['id'];
                $_SESSION['totp_pending_time'] = time();
                return 'totp_required';
            }

            $this->notifyIfNewIp($user, $ip);
            $this->setUserSession($user);
            $this->updateLastLogin($user['id']);
            $this->writeAuditLog($user['id'], 'user_login', 'user', $user['id'], 'Login erfolgreich');
            return true;
        }

        $this->recordLoginAttempt($ip, $email);
        return false;
    }

    /** Webhook bei Login von einer für diesen Nutzer bisher unbekannten IP. */
    private function notifyIfNewIp($user, $ip) {
        try {
            $seen = $this->db->fetchOne(
                "SELECT 1 FROM audit_log WHERE user_id = ? AND action = 'user_login' AND ip_address = ? LIMIT 1",
                [$user['id'], $ip]
            );
            if (!$seen) {
                Notifier::loginNewIp($user['email'], $ip);
            }
        } catch (\Exception $e) { /* nie blockierend */ }
    }

    /** Liegt ein Login vor, der noch auf den 2FA-Code wartet? */
    public function isTotpPending() {
        return isset($_SESSION['totp_pending_user_id'])
            && isset($_SESSION['totp_pending_time'])
            && (time() - (int)$_SESSION['totp_pending_time']) < 300; // 5 Min Fenster
    }

    /** Schliesst einen 2FA-Login mit dem eingegebenen Code ab. */
    public function verifyTotpLogin($code) {
        if (!$this->isTotpPending()) {
            return false;
        }
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE id = ? AND is_active = 1",
            [$_SESSION['totp_pending_user_id']]
        );
        if (!$user || empty($user['totp_secret'])) {
            unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_time']);
            return false;
        }
        // Entweder gueltiger TOTP-Code ODER ein Recovery-/Backup-Code
        // (Secret wird verschluesselt gespeichert; Klartext-Fallback via decrypt)
        $ok = Totp::verify(Crypto::decrypt($user['totp_secret']), $code);
        if (!$ok && $this->consumeBackupCode($user, $code)) {
            $ok = true;
            $this->writeAuditLog($user['id'], 'user_login_backup_code', 'user', $user['id'], 'Login per Recovery-Code');
        }
        if (!$ok) {
            $this->writeAuditLog($user['id'], 'user_login_2fa_failed', 'user', $user['id'], '2FA-Code falsch');
            return false;
        }
        unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_time']);
        $this->notifyIfNewIp($user, $this->clientIp());
        $this->setUserSession($user);
        $this->updateLastLogin($user['id']);
        $this->writeAuditLog($user['id'], 'user_login', 'user', $user['id'], 'Login erfolgreich (2FA)');
        return true;
    }

    /**
     * 2FA fuer einen Benutzer aktivieren und Recovery-Codes erzeugen.
     * @return array Klartext-Recovery-Codes (nur hier einmalig verfuegbar)
     */
    public function enableTotp($userId, $secret) {
        $codes = $this->generateBackupCodes();
        $hashes = array_map(function ($c) { return hash('sha256', $c); }, $codes);
        $this->db->query(
            "UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_backup_codes = ? WHERE id = ?",
            [Crypto::encrypt($secret), json_encode($hashes), $userId]
        );
        $this->writeAuditLog($userId, 'totp_enabled', 'user', $userId, '2FA aktiviert');
        return $codes;
    }

    /** 2FA fuer einen Benutzer deaktivieren. */
    public function disableTotp($userId) {
        $this->db->query(
            "UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_backup_codes = NULL WHERE id = ?",
            [$userId]
        );
        $this->writeAuditLog($userId, 'totp_disabled', 'user', $userId, '2FA deaktiviert');
    }

    /** Neue Recovery-Codes erzeugen (8 Stueck, Format XXXX-XXXX). */
    public function generateBackupCodes($count = 8) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4))); // 8 Hex-Zeichen
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }
        return $codes;
    }

    /** Recovery-Codes neu erzeugen und speichern; gibt Klartext zurueck. */
    public function regenerateBackupCodes($userId) {
        $codes = $this->generateBackupCodes();
        $hashes = array_map(function ($c) { return hash('sha256', $c); }, $codes);
        $this->db->query("UPDATE users SET totp_backup_codes = ? WHERE id = ?", [json_encode($hashes), $userId]);
        $this->writeAuditLog($userId, 'totp_backup_regenerated', 'user', $userId, 'Recovery-Codes neu erzeugt');
        return $codes;
    }

    /** Anzahl noch nicht verbrauchter Recovery-Codes. */
    public function backupCodesRemaining($user) {
        $list = json_decode($user['totp_backup_codes'] ?? '[]', true);
        return is_array($list) ? count($list) : 0;
    }

    /** Prueft & verbraucht einen Recovery-Code (konstante Zeit). */
    private function consumeBackupCode($user, $code) {
        $code = strtoupper(trim($code));
        $list = json_decode($user['totp_backup_codes'] ?? '[]', true);
        if (!is_array($list) || empty($list)) {
            return false;
        }
        $hash = hash('sha256', $code);
        $matched = false;
        $remaining = [];
        foreach ($list as $h) {
            if (!$matched && hash_equals($h, $hash)) {
                $matched = true; // diesen verbrauchen (nicht behalten)
            } else {
                $remaining[] = $h;
            }
        }
        if ($matched) {
            $this->db->query("UPDATE users SET totp_backup_codes = ? WHERE id = ?", [json_encode($remaining), $user['id']]);
        }
        return $matched;
    }

    public function writeAuditLog($userId, $action, $entityType = null, $entityId = null, $details = null) {
        try {
            $this->db->execute(
                "INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $action, $entityType, $entityId !== null ? (string)$entityId : null, $details, $this->clientIp()]
            );
        } catch (\Exception $e) {
            // audit_log table may not exist on old installs
        }
    }

    private function isRateLimited($ip, $email) {
        try {
            $count = $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM login_attempts
                 WHERE (ip_address = ? OR email = ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                [$ip, $email]
            );
            return $count && (int)$count['cnt'] >= 10;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function recordLoginAttempt($ip, $email) {
        try {
            $this->db->query(
                "INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)",
                [$ip, $email]
            );
        } catch (\Exception $e) {
            // Tabelle existiert noch nicht – ignorieren
        }
    }

    private function clearLoginAttempts($ip, $email) {
        try {
            $this->db->query(
                "DELETE FROM login_attempts WHERE ip_address = ? OR email = ?",
                [$ip, $email]
            );
        } catch (\Exception $e) {
            // ignore
        }
    }
    
    // Microsoft 365 Login
    public function loginWithMicrosoft($microsoftUser) {
        // Zuerst nach Microsoft ID suchen
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE microsoft_id = ? AND is_active = 1",
            [$microsoftUser['id']]
        );
        
        if (!$user) {
            // Prüfen ob E-Mail bereits existiert (ohne Microsoft ID)
            $existingUser = $this->db->fetchOne(
                "SELECT * FROM users WHERE email = ? AND is_active = 1",
                [$microsoftUser['email']]
            );
            
            if ($existingUser) {
                // Benutzer existiert bereits ohne Microsoft ID - verknüpfen
                $this->db->query(
                    "UPDATE users SET microsoft_id = ?, name = ? WHERE id = ?",
                    [$microsoftUser['id'], $microsoftUser['name'], $existingUser['id']]
                );
                $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$existingUser['id']]);
            } else {
                // Komplett neuer Benutzer - anlegen
                $userId = $this->db->execute(
                    "INSERT INTO users (email, name, microsoft_id, is_active) VALUES (?, ?, ?, 1)",
                    [$microsoftUser['email'], $microsoftUser['name'], $microsoftUser['id']]
                );
                
                $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
            }
        } else {
            // Microsoft-Benutzer existiert bereits - Name aktualisieren falls geändert
            $this->db->query(
                "UPDATE users SET name = ? WHERE id = ?",
                [$microsoftUser['name'], $user['id']]
            );
        }
        
        $this->setUserSession($user);
        $this->updateLastLogin($user['id']);
        return true;
    }
    
    // Session setzen
    private function setUserSession($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['is_admin'] = (bool)$user['is_admin'];
        $_SESSION['login_time'] = time();
        
        // CSRF-Token generieren
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
    
    // Letzten Login aktualisieren
    private function updateLastLogin($userId) {
        $this->db->query(
            "UPDATE users SET last_login = NOW() WHERE id = ?",
            [$userId]
        );
    }
    
    // Ausloggen
    public function logout() {
        $_SESSION = [];
        
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        session_destroy();
    }
    
    // Prüfen ob eingeloggt
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_time'])) {
            return false;
        }

        // Absolutes Session-Timeout durchsetzen (SESSION_LIFETIME aus config.php).
        // Bisher wurde die Lebensdauer nie geprueft – Sessions liefen unbegrenzt.
        $lifetime = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 3600;
        if ($lifetime > 0 && (time() - (int)$_SESSION['login_time']) > $lifetime) {
            $this->logout();
            return false;
        }

        return true;
    }
    
    // Prüfen ob Admin
    public function isAdmin() {
        return $this->isLoggedIn() && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
    }
    
    // Aktuellen Benutzer abrufen
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return $this->db->fetchOne(
            "SELECT * FROM users WHERE id = ?",
            [$_SESSION['user_id']]
        );
    }
    
    // Prüfen ob Benutzer Zugriff auf Site hat
    public function hasAccessToSite($siteId) {
        if ($this->isAdmin()) {
            return true;
        }
        
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $access = $this->db->fetchOne(
            "SELECT id FROM user_site_access WHERE user_id = ? AND site_id = ?",
            [$_SESSION['user_id'], $siteId]
        );
        
        return $access !== false;
    }
    
    // CSRF-Token validieren
    public function validateCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    // CSRF-Token abrufen
    public function getCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    // Benutzer registrieren (nur für Admins)
    public function registerUser($email, $name, $password, $isAdmin = false) {
        // Prüfen ob E-Mail bereits existiert
        $existing = $this->db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
        if ($existing) {
            return false;
        }
        
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        return $this->db->execute(
            "INSERT INTO users (email, name, password_hash, is_admin, is_active) VALUES (?, ?, ?, ?, 1)",
            [$email, $name, $passwordHash, $isAdmin ? 1 : 0]
        );
    }
    
    // Admin-Zugriff erforderlich
    public function requireAdmin() {
        if (!$this->isAdmin()) {
            header('Location: /index.php?error=access_denied');
            exit;
        }
        // Optionale Richtlinie: 2FA fuer Admins erzwingen. Admins ohne aktives
        // 2FA werden zur Einrichtung umgeleitet (security.php nutzt requireLogin,
        // daher keine Endlosschleife).
        try {
            if ((string)$this->db->getSetting('enforce_2fa_admins', '0') === '1') {
                $user = $this->getCurrentUser();
                $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
                if ($user && !empty($user['password_hash']) && empty($user['totp_enabled'])
                    && $script !== 'security.php') {
                    header('Location: security.php?setup_required=1');
                    exit;
                }
            }
        } catch (\Exception $e) { /* Richtlinie nie blockierend */ }
    }
    
    // Login erforderlich
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }
}