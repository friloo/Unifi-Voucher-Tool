<?php
/**
 * Totp – minimaler TOTP-Generator/-Validator nach RFC 6238 (HMAC-SHA1,
 * 6 Stellen, 30s-Zeitfenster). Reine PHP-Standardlib, keine Abhaengigkeiten.
 * Kompatibel mit Google Authenticator, Authy, Microsoft Authenticator etc.
 */
class Totp {
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Erzeugt ein neues Base32-Secret (Standard 16 Zeichen = 80 bit). */
    public static function generateSecret($length = 16) {
        $secret = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    /** Aktueller Code fuer ein Secret. */
    public static function code($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = (int) floor(time() / self::PERIOD);
        }
        $key = self::base32Decode($secret);
        // 8-Byte Big-Endian Counter
        $binTime = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $binTime, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;
        $mod = $value % (10 ** self::DIGITS);
        return str_pad((string)$mod, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Prueft einen Code mit Toleranzfenster (+/- $window Zeitschritte gegen
     * Uhren-Drift). Konstante-Zeit-Vergleich gegen Timing-Angriffe.
     */
    public static function verify($secret, $code, $window = 1) {
        if (!preg_match('/^\d{6}$/', (string)$code)) {
            return false;
        }
        $current = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $current + $i), (string)$code)) {
                return true;
            }
        }
        return false;
    }

    /** otpauth://-URI fuer QR-Code-Provisionierung. */
    public static function provisioningUri($secret, $accountName, $issuer) {
        $label = rawurlencode($issuer . ':' . $accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
        return "otpauth://totp/$label?$params";
    }

    private static function base32Decode($b32) {
        $b32 = strtoupper(rtrim($b32, '='));
        $buffer = 0; $bitsLeft = 0; $out = '';
        for ($i = 0; $i < strlen($b32); $i++) {
            $val = strpos(self::BASE32, $b32[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $out .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $out;
    }
}
