<?php
/**
 * Kleine Shared-Helper:
 *  - Session-Flash-Messages fuer das PRG-Pattern (Redirect nach POST,
 *    Erfolgsmeldung ueberlebt den Redirect, F5 wiederholt keine Aktion).
 *  - IP-basiertes Request-Throttling ueber die Tabelle request_throttle.
 */

function flashSet($message, $type = 'success') {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** @return array|null ['type' => ..., 'message' => ...] oder null */
function flashGet() {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Zaehlt eine Aktion fuer die aktuelle IP und prueft das Limit.
 *
 * @param Database $db
 * @param string   $action        Logischer Name, z.B. 'voucher_create'
 * @param int      $maxWeight     Erlaubte Summe im Zeitfenster
 * @param int      $windowMinutes Zeitfenster in Minuten
 * @param int      $weight        Gewicht dieser Anfrage (z.B. Bulk-Anzahl)
 * @return bool|null true = limitiert, false = erlaubt (und gezaehlt),
 *                   null = Tabelle fehlt (Aufrufer entscheidet ueber Fallback)
 */
function throttleHit($db, $action, $maxWeight, $windowMinutes, $weight = 1) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $db->query("DELETE FROM request_throttle WHERE requested_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $row = $db->fetchOne(
            "SELECT COALESCE(SUM(weight), 0) AS cnt FROM request_throttle
             WHERE action = ? AND ip_address = ?
               AND requested_at > DATE_SUB(NOW(), INTERVAL " . (int)$windowMinutes . " MINUTE)",
            [$action, $ip]
        );
        if ((int)$row['cnt'] + $weight > $maxWeight) {
            return true;
        }
        $db->query(
            "INSERT INTO request_throttle (ip_address, action, weight) VALUES (?, ?, ?)",
            [$ip, $action, $weight]
        );
        return false;
    } catch (Exception $e) {
        // Tabelle existiert noch nicht (Migration 0002 nicht gelaufen)
        return null;
    }
}
