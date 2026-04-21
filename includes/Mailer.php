<?php
class Mailer {
    private $db;
    private $smtpEnabled;
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;
    private $smtpEncryption;
    private $fromEmail;
    private $fromName;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadSettings();
    }
    
    private function loadSettings() {
        $this->smtpEnabled = $this->db->getSetting('smtp_enabled', '0') === '1';
        $this->smtpHost = $this->db->getSetting('smtp_host', '');
        $this->smtpPort = (int)$this->db->getSetting('smtp_port', '587');
        $this->smtpUsername = $this->db->getSetting('smtp_username', '');
        $this->smtpPassword = $this->db->getSetting('smtp_password', '');
        $this->smtpEncryption = $this->db->getSetting('smtp_encryption', 'tls');
        $this->fromEmail = $this->db->getSetting('smtp_from_email', 'noreply@' . $_SERVER['HTTP_HOST']);
        $this->fromName = $this->db->getSetting('smtp_from_name', $this->db->getSetting('app_title', 'UniFi Voucher System'));
    }
    
    public function send($to, $subject, $body, $isHtml = false) {
        if (!$this->smtpEnabled || empty($this->smtpHost)) {
            // Fallback auf PHP mail()
            return $this->sendWithPhpMail($to, $subject, $body);
        }
        
        return $this->sendWithSmtp($to, $subject, $body, $isHtml);
    }
    
    private function sendWithPhpMail($to, $subject, $body) {
        $headers = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $headers .= "Reply-To: {$this->fromEmail}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        return mail($to, $subject, $body, $headers);
    }
    
    private function sendWithSmtp($to, $subject, $body, $isHtml = false) {
        try {
            // Verbindung aufbauen
            $socket = $this->connectToSmtp();
            
            // EHLO
            $this->smtpCommand($socket, "EHLO " . $_SERVER['HTTP_HOST']);
            
            // STARTTLS wenn nötig
            if ($this->smtpEncryption === 'tls') {
                $this->smtpCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->smtpCommand($socket, "EHLO " . $_SERVER['HTTP_HOST']);
            }
            
            // AUTH LOGIN
            $this->smtpCommand($socket, "AUTH LOGIN");
            $this->smtpCommand($socket, base64_encode($this->smtpUsername));
            $this->smtpCommand($socket, base64_encode($this->smtpPassword));
            
            // MAIL FROM
            $this->smtpCommand($socket, "MAIL FROM:<{$this->fromEmail}>");
            
            // RCPT TO
            $this->smtpCommand($socket, "RCPT TO:<{$to}>");
            
            // DATA
            $this->smtpCommand($socket, "DATA");
            
            // Headers
            $message = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
            $message .= "To: {$to}\r\n";
            $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            
            if ($isHtml) {
                $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            } else {
                $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            }
            
            $message .= "\r\n";
            
            // Body - bei Plain Text Zeilenumbrüche konvertieren
            if (!$isHtml) {
                $body = nl2br($body, false); // Für Plain Text
                $body = str_replace('<br>', "\r\n", $body);
            }
            
            $message .= $body;
            $message .= "\r\n.\r\n";
            
            fwrite($socket, $message);
            $response = fgets($socket);
            
            // QUIT
            $this->smtpCommand($socket, "QUIT");
            fclose($socket);
            
            return strpos($response, '250') === 0;
            
        } catch (Exception $e) {
            error_log("SMTP Error: " . $e->getMessage());
            return false;
        }
    }
    
    private function connectToSmtp() {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        if ($this->smtpEncryption === 'ssl') {
            $host = 'ssl://' . $this->smtpHost;
        } else {
            $host = $this->smtpHost;
        }
        
        $socket = stream_socket_client(
            $host . ':' . $this->smtpPort,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$socket) {
            throw new Exception("SMTP Connection failed: $errstr ($errno)");
        }
        
        // Willkommensnachricht lesen
        fgets($socket);
        
        return $socket;
    }
    
    private function smtpCommand($socket, $command) {
        fwrite($socket, $command . "\r\n");
        $response = fgets($socket);
        
        // Prüfen auf Fehler (4xx oder 5xx)
        if (preg_match('/^[45]/', $response)) {
            throw new Exception("SMTP Error: $response");
        }
        
        return $response;
    }
    
    // Vordefinierte E-Mail-Templates
    public function sendVoucherEmail($to, $voucherCode, $siteName, $maxUses) {
        $appTitle = $this->db->getSetting('app_title', 'UniFi Voucher System');
        $instructionHeader = $this->db->getSetting('instruction_header', '');
        $instructionText = $this->db->getSetting('instruction_text', '');
        
        // System-URL aus Einstellungen oder automatisch erkennen
        $systemUrl = $this->db->getSetting('system_url', '');
        if (empty($systemUrl)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
            $scriptPath = $scriptPath === '/' ? '' : $scriptPath;
            $systemUrl = $protocol . '://' . $host . $scriptPath;
        }
        
        // Template aus Datenbank laden
        $subjectTemplate = $this->db->getSetting('email_voucher_subject', '{APP_TITLE} - Ihr WLAN-Zugang');
        $bodyTemplate = $this->db->getSetting('email_voucher_body', "Hallo,\n\nIhr WLAN-Zugangscode lautet:\n\n<strong>{VOUCHER_CODE}</strong>\n\nGültigkeit: 8 Stunden ab Erstellung\nMaximale Geräte: {MAX_USES}\nStandort: {SITE_NAME}\n\n{INSTRUCTIONS}\n\nMit freundlichen Grüßen\n{APP_TITLE}");
        
        // Anleitung formatieren
        $instructions = '';
        if ($instructionText) {
            $instructions = $instructionHeader . "\n" . $instructionText;
        }
        
        // Platzhalter ersetzen
        $placeholders = [
            '{VOUCHER_CODE}' => $voucherCode,
            '{SITE_NAME}' => $siteName,
            '{MAX_USES}' => $maxUses,
            '{APP_TITLE}' => $appTitle,
            '{INSTRUCTIONS}' => $instructions,
            '{SYSTEM_URL}' => $systemUrl
        ];
        
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subjectTemplate);
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTemplate);
        
        // HTML oder Plain Text prüfen
        $isHtml = strip_tags($body) !== $body;
        
        return $this->send($to, $subject, $body, $isHtml);
    }
    
    public function sendUserNotification($to, $userName, $changes) {
        $appTitle = $this->db->getSetting('app_title', 'UniFi Voucher System');
        
        // System-URL aus Einstellungen oder automatisch erkennen
        $systemUrl = $this->db->getSetting('system_url', '');
        if (empty($systemUrl)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
            $scriptPath = $scriptPath === '/' ? '' : $scriptPath;
            $systemUrl = $protocol . '://' . $host . $scriptPath;
        }
        
        // Template aus Datenbank laden
        $subjectTemplate = $this->db->getSetting('email_user_notification_subject', '{APP_TITLE} - Ihre Berechtigungen wurden geändert');
        $bodyTemplate = $this->db->getSetting('email_user_notification_body', "Hallo {USER_NAME},\n\nEin Administrator hat Ihre Berechtigungen im {APP_TITLE} geändert:\n\n{CHANGES}\n\nSie können sich unter folgender Adresse anmelden:\n{SYSTEM_URL}\n\nMit freundlichen Grüßen\n{APP_TITLE}");
        
        // Änderungen formatieren
        $changesText = '';
        foreach ($changes as $change) {
            $changesText .= "• $change\n";
        }
        
        // Platzhalter ersetzen
        $placeholders = [
            '{USER_NAME}' => $userName,
            '{CHANGES}' => $changesText,
            '{APP_TITLE}' => $appTitle,
            '{SYSTEM_URL}' => $systemUrl
        ];
        
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subjectTemplate);
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTemplate);
        
        // HTML oder Plain Text prüfen
        $isHtml = strip_tags($body) !== $body;
        
        return $this->send($to, $subject, $body, $isHtml);
    }
}