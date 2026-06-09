<?php
/**
 * Crypto - Symmetrische Verschluesselung fuer sensible Felder (z.B. UniFi-Passwoerter).
 *
 * Designziele:
 *  - Verschluesselung-at-rest mit einem Schluessel (APP_KEY) aus der config.php.
 *  - Vollstaendige Abwaertskompatibilitaet: Bestehende Installationen ohne APP_KEY
 *    und bereits im Klartext gespeicherte Werte funktionieren unveraendert weiter.
 *    decrypt() gibt Werte, die nicht unserem Ciphertext-Format entsprechen,
 *    unveraendert zurueck (Klartext-Passthrough).
 *  - encrypt() verschluesselt nur, wenn ein APP_KEY vorhanden ist – sonst Passthrough.
 *
 * Format des Ciphertexts: "enc:v1:" . base64(nonce|ciphertext)
 */
class Crypto {
    private const PREFIX = 'enc:v1:';

    /** Liefert den 32-Byte-Schluessel oder null, wenn kein/ungueltiger APP_KEY gesetzt ist. */
    private static function key() {
        if (!defined('APP_KEY') || APP_KEY === '') {
            return null;
        }
        $key = base64_decode(APP_KEY, true);
        if ($key === false || strlen($key) !== 32) {
            return null;
        }
        return $key;
    }

    /** Erzeugt einen neuen, base64-kodierten 32-Byte-Schluessel fuer die config.php. */
    public static function generateKey() {
        return base64_encode(random_bytes(32));
    }

    /**
     * Verschluesselt einen Klartext. Ohne gueltigen APP_KEY wird der Wert
     * unveraendert zurueckgegeben (kein Bruch bestehender Installationen).
     */
    public static function encrypt($plaintext) {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }
        $key = self::key();
        if ($key === null) {
            return $plaintext; // Kein Schluessel -> Klartext (Legacy-Verhalten)
        }

        // Bevorzugt libsodium (PHP-Core seit 7.2), sonst OpenSSL.
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return self::PREFIX . base64_encode($nonce . $cipher);
        }
        if (function_exists('openssl_encrypt')) {
            $ivLen = openssl_cipher_iv_length('aes-256-gcm');
            $iv = random_bytes($ivLen);
            $tag = '';
            $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) {
                return $plaintext;
            }
            return self::PREFIX . base64_encode($iv . $tag . $cipher);
        }

        // Keine Krypto-Funktion verfuegbar -> Klartext (besser als Datenverlust)
        return $plaintext;
    }

    /**
     * Entschluesselt einen Wert. Nicht-verschluesselte Werte (Legacy/Klartext)
     * werden unveraendert zurueckgegeben.
     */
    public static function decrypt($value) {
        if ($value === null || $value === '' || strpos($value, self::PREFIX) !== 0) {
            return $value; // Klartext-Passthrough
        }
        $key = self::key();
        if ($key === null) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false) {
            return $value;
        }

        if (function_exists('sodium_crypto_secretbox_open')) {
            $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($raw) <= $nonceLen) {
                return $value;
            }
            $nonce = substr($raw, 0, $nonceLen);
            $cipher = substr($raw, $nonceLen);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return $plain === false ? $value : $plain;
        }
        if (function_exists('openssl_decrypt')) {
            $ivLen = openssl_cipher_iv_length('aes-256-gcm');
            $tagLen = 16;
            if (strlen($raw) <= $ivLen + $tagLen) {
                return $value;
            }
            $iv = substr($raw, 0, $ivLen);
            $tag = substr($raw, $ivLen, $tagLen);
            $cipher = substr($raw, $ivLen + $tagLen);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? $value : $plain;
        }

        return $value;
    }

    /** Prueft, ob ein Wert bereits in unserem verschluesselten Format vorliegt. */
    public static function isEncrypted($value) {
        return is_string($value) && strpos($value, self::PREFIX) === 0;
    }

    /** Prueft, ob ein gueltiger APP_KEY konfiguriert ist (fuer Admin-Warnhinweis). */
    public static function hasKey() {
        return self::key() !== null;
    }
}
