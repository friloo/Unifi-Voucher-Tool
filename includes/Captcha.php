<?php
/**
 * Captcha – Schutz der öffentlichen (anonymen) Voucher-Erstellung.
 *
 * Modi (Setting captcha_mode):
 *   'off'      – deaktiviert
 *   'math'     – selbst-enthaltenes Rechen-Captcha (keine externen Dienste)
 *   'hcaptcha' – hCaptcha (Setting captcha_site_key / captcha_secret)
 *
 * Reine PHP-Standardlib + cURL (für hCaptcha-Verifizierung).
 */
class Captcha {
    public static function mode($db) {
        $m = (string)$db->getSetting('captcha_mode', 'off');
        return in_array($m, ['off', 'math', 'hcaptcha'], true) ? $m : 'off';
    }

    /** Frage für das Math-Captcha erzeugen und Antwort in Session hinterlegen. */
    public static function newMathChallenge() {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $_SESSION['captcha_answer'] = (string)($a + $b);
        return "$a + $b";
    }

    /** Prüft die Captcha-Antwort des aktuellen Requests. */
    public static function verify($db) {
        $mode = self::mode($db);
        if ($mode === 'off') {
            return true;
        }
        if ($mode === 'math') {
            $expected = $_SESSION['captcha_answer'] ?? null;
            unset($_SESSION['captcha_answer']); // einmalig
            $given = trim((string)($_POST['captcha'] ?? ''));
            return $expected !== null && hash_equals((string)$expected, $given);
        }
        if ($mode === 'hcaptcha') {
            $resp = $_POST['h-captcha-response'] ?? '';
            if ($resp === '') return false;
            $secret = (string)$db->getSetting('captcha_secret', '');
            $ch = curl_init('https://hcaptcha.com/siteverify');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $resp]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
            ]);
            $out = curl_exec($ch);
            curl_close($ch);
            $data = json_decode((string)$out, true);
            return is_array($data) && !empty($data['success']);
        }
        return true;
    }
}
