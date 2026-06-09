<?php
/**
 * Sms – Versand von Voucher-Codes per SMS über Twilio.
 *
 * Settings: sms_enabled (0/1), twilio_sid, twilio_token, twilio_from
 * Reine PHP-Standardlib + cURL. Fehler werden geloggt, nie geworfen.
 */
class Sms {
    public static function enabled($db) {
        return (string)$db->getSetting('sms_enabled', '0') === '1'
            && $db->getSetting('twilio_sid', '') !== ''
            && $db->getSetting('twilio_token', '') !== ''
            && $db->getSetting('twilio_from', '') !== '';
    }

    /** Sendet eine SMS. Gibt true bei Erfolg zurück. */
    public static function send($db, $to, $text) {
        if (!self::enabled($db)) {
            return false;
        }
        $sid   = (string)$db->getSetting('twilio_sid', '');
        $token = (string)$db->getSetting('twilio_token', '');
        $from  = (string)$db->getSetting('twilio_from', '');

        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/" . rawurlencode($sid) . "/Messages.json");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $sid . ':' . $token,
            CURLOPT_POSTFIELDS => http_build_query(['From' => $from, 'To' => $to, 'Body' => $text]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $out  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            return true;
        }
        error_log("Sms(Twilio) Fehler HTTP $code: " . substr((string)$out, 0, 200));
        return false;
    }
}
