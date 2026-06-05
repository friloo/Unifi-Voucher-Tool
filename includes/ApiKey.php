<?php
/**
 * ApiKey – Erzeugung & Verifizierung von API-Schlüsseln für die REST-API.
 *
 * Schlüsselformat:  uvt_<prefix(8)><secret(32)>
 * Gespeichert wird nur der SHA-256-Hash plus ein indexierbares Präfix – der
 * Klartext-Schlüssel wird dem Admin genau einmal bei der Erstellung gezeigt.
 */
class ApiKey {
    /** Neuen Schlüssel erzeugen. Gibt plain/prefix/hash zurück. */
    public static function generate() {
        $prefix = bin2hex(random_bytes(4));   // 8 Zeichen
        $secret = bin2hex(random_bytes(16));   // 32 Zeichen
        $plain  = 'uvt_' . $prefix . $secret;
        return [
            'plain'  => $plain,
            'prefix' => $prefix,
            'hash'   => hash('sha256', $plain),
        ];
    }

    /**
     * Verifiziert einen Klartext-Schlüssel gegen die DB. Aktualisiert
     * last_used_at und gibt den Key-Datensatz zurück – oder false.
     */
    public static function verify($plain, $db) {
        if (!is_string($plain) || strpos($plain, 'uvt_') !== 0 || strlen($plain) < 44) {
            return false;
        }
        $prefix = substr($plain, 4, 8);
        $hash   = hash('sha256', $plain);
        try {
            $row = $db->fetchOne(
                "SELECT * FROM api_keys WHERE key_prefix = ? AND is_active = 1",
                [$prefix]
            );
        } catch (\Exception $e) {
            return false;
        }
        if (!$row || !hash_equals($row['key_hash'], $hash)) {
            return false;
        }
        try {
            $db->query("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?", [$row['id']]);
        } catch (\Exception $e) { /* ignore */ }
        return $row;
    }

    /**
     * Fixed-Window-Rate-Limit pro Schlüssel (Anfragen/Minute). rate_limit = 0
     * bedeutet unbegrenzt. Gibt true zurück, wenn die Anfrage erlaubt ist.
     */
    public static function checkRateLimit($row, $db) {
        $limit = (int)($row['rate_limit'] ?? 0);
        if ($limit <= 0) {
            return true;
        }
        try {
            // alte Treffer (>60s) aufräumen
            $db->query("DELETE FROM api_key_hits WHERE api_key_id = ? AND hit_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)", [$row['id']]);
            $cnt = $db->fetchOne("SELECT COUNT(*) AS c FROM api_key_hits WHERE api_key_id = ?", [$row['id']]);
            if ($cnt && (int)$cnt['c'] >= $limit) {
                return false;
            }
            $db->query("INSERT INTO api_key_hits (api_key_id) VALUES (?)", [$row['id']]);
        } catch (\Exception $e) {
            return true; // Bei Fehlern (z.B. Tabelle fehlt) nicht blockieren
        }
        return true;
    }

    /** Prüft, ob der Schlüssel den geforderten Scope hat ('read' < 'write'). */
    public static function hasScope($row, $needed) {
        $scope = $row['scope'] ?? 'write';
        if ($needed === 'read') {
            return in_array($scope, ['read', 'write'], true);
        }
        return $scope === 'write';
    }

    /** Liest den Schlüssel aus dem Request (Authorization: Bearer / X-API-Key). */
    public static function fromRequest() {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                $headers[strtolower($k)] = $v;
            }
        }
        if (!empty($headers['authorization']) && preg_match('/Bearer\s+(\S+)/i', $headers['authorization'], $m)) {
            return $m[1];
        }
        if (!empty($headers['x-api-key'])) {
            return trim($headers['x-api-key']);
        }
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            return $m[1];
        }
        return null;
    }
}
