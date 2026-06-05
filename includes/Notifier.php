<?php
/**
 * Notifier – sendet Ereignis-Benachrichtigungen an einen konfigurierten
 * Webhook (Slack / Microsoft Teams / generischer JSON-Endpunkt).
 *
 * Gesteuert über Einstellungen:
 *   webhook_enabled (0/1), webhook_url
 *
 * Sendet ein Slack/Teams-kompatibles { "text": "..." }-Payload plus ein
 * strukturiertes "event"-Feld. Fehler werden still ignoriert – eine
 * Benachrichtigung darf nie den eigentlichen Vorgang blockieren.
 */
class Notifier {
    /** Generisches Event senden. */
    public static function send($text, array $data = []) {
        try {
            $db = Database::getInstance();
            if ((string)$db->getSetting('webhook_enabled', '0') !== '1') {
                return;
            }
            $url = trim((string)$db->getSetting('webhook_url', ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $payload = json_encode(array_merge(['text' => $text], $data ? ['event' => $data] : []));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }

    /** Bequemer Helfer für erstellte Voucher. */
    public static function voucherCreated($count, $siteName, $byUser = null) {
        $who = $byUser ? " von {$byUser}" : '';
        $what = $count > 1 ? "{$count} Voucher" : 'Ein Voucher';
        self::send(
            "🎫 {$what} für \"{$siteName}\"{$who} erstellt.",
            ['type' => 'voucher_created', 'count' => $count, 'site' => $siteName, 'user' => $byUser]
        );
    }
}
