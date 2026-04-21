<?php
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
    
    // Benutzer einloggen
    public function login($email, $password) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($this->isRateLimited($ip, $email)) {
            return 'rate_limited';
        }

        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE email = ? AND is_active = 1",
            [$email]
        );

        if ($user && password_verify($password, $user['password_hash'])) {
            $this->clearLoginAttempts($ip, $email);
            $this->setUserSession($user);
            $this->updateLastLogin($user['id']);
            return true;
        }

        $this->recordLoginAttempt($ip, $email);
        return false;
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
        return isset($_SESSION['user_id']) && isset($_SESSION['login_time']);
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
    }
    
    // Login erforderlich
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }
}