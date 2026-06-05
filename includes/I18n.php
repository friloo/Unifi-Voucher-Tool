<?php

class I18n {
    private static array $translations = [];
    private static string $language = 'de';
    private static bool $initialized = false;

    public static function init(): void {
        if (self::$initialized) return;

        if (!isset($_SESSION)) {
            if (session_status() === PHP_SESSION_NONE) session_start();
        }

        if (isset($_GET['set_lang']) && in_array($_GET['set_lang'], ['de', 'en'], true)) {
            $_SESSION['language'] = $_GET['set_lang'];
        }

        self::$language = $_SESSION['language'] ?? 'de';

        $langFile = __DIR__ . '/../lang/' . self::$language . '.php';
        if (file_exists($langFile)) {
            self::$translations = require $langFile;
        } else {
            $fallback = __DIR__ . '/../lang/de.php';
            if (file_exists($fallback)) {
                self::$translations = require $fallback;
            }
        }

        self::$initialized = true;
    }

    public static function t(string $key, array $replace = []): string {
        $text = self::$translations[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
        return $text;
    }

    public static function getLanguage(): string {
        return self::$language;
    }

    public static function getAvailable(): array {
        return ['de' => 'Deutsch', 'en' => 'English'];
    }
}

function __($key, array $replace = []): string {
    return I18n::t($key, $replace);
}
