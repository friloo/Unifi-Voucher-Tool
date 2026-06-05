<?php
namespace Updater;

/**
 * Zentrale Fabrik fuer den UpdateManager.
 *
 * Der Channel wird ausschliesslich aus einer EIGENEN, isolierten Settings-Datei
 * gelesen (updater/storage/updater-settings.json) – NICHT aus der bestehenden
 * Settings-/Config-Konvention des Projekts. Beim Rueckbau einfach loeschbar.
 *
 * Default-Channel-Fallback existiert NUR hier (eine Stelle).
 */
final class UpdaterFactory
{
    private static function settingsFile(): string
    {
        return __DIR__ . '/storage/updater-settings.json';
    }

    public static function create(\Database $db, ?AuditLogger $audit = null): UpdateManager
    {
        $settings = self::loadSettings();
        $channel = $settings['channel'] ?? 'stable';
        return new UpdateManager($db, $audit, $channel);
    }

    public static function loadSettings(): array
    {
        $file = self::settingsFile();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public static function saveChannel(string $channel): void
    {
        if (!isset(UpdateManager::CHANNELS[$channel])) {
            $channel = 'stable';
        }
        $settings = self::loadSettings();
        $settings['channel'] = $channel;

        $dir = dirname(self::settingsFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(self::settingsFile(), json_encode($settings, JSON_PRETTY_PRINT));
    }
}
