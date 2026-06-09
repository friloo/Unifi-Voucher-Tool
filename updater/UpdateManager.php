<?php
namespace Updater;

/**
 * UpdateManager – zieht Quellcode + DB-Migrationen aus einem zentralen
 * Repository ueber einen HTTP-Update-Proxy nach.
 *
 * Architektur:  [diese App] <-HTTPS-> [Update-Proxy] <-pull- [Git-Repo]
 * Versions-Identitaet: 40-stelliger Git-Commit-SHA in <storage>/.version.
 *
 * Vollstaendig isoliert im updater/-Ordner; nutzt die vorhandene
 * \Database-Klasse per Injection (keine Erweiterung von Projekt-Klassen).
 */
class UpdateManager
{
    public const CHANNELS = [
        'stable'      => 'https://update.loheide.eu/openvouchertool',
        'development' => 'https://update.loheide.eu/openvouchertool-development',
    ];

    private const USER_AGENT = 'OpenVoucherTool-Updater/1.0';

    /**
     * Pfade, die beim Staging->Production-Move NIEMALS ueberschrieben werden.
     * Relativ zum Projekt-Root. Prefix-Match (Ordner mit Slash).
     */
    private const PROTECTED_PATHS = [
        'config.php',
        '.htaccess',
        '.env',
        '.env.example',
        '.git/',
        '.gitignore',
        'public/uploads/',
        'vendor/',
        'composer.lock',
        // Updater-Laufzeitdaten (Version, Settings, Staging) – nie ueberschreiben.
        // Der restliche updater/-Code SOLL aktualisiert werden.
        'updater/storage/',
    ];

    /** @var \Database */
    private $db;
    /** @var AuditLogger|null */
    private $audit;
    private $channel;
    private $proxyUrl;
    private $rootDir;
    private $storageDir;

    public function __construct(\Database $db, ?AuditLogger $audit = null, string $channel = 'stable')
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->channel = isset(self::CHANNELS[$channel]) ? $channel : 'stable';
        $this->proxyUrl = self::CHANNELS[$this->channel];
        $this->rootDir = dirname(__DIR__);        // updater/ liegt im Projekt-Root
        $this->storageDir = __DIR__ . '/storage'; // updater/storage/
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0775, true);
        }
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getProxyUrl(): string
    {
        return $this->proxyUrl;
    }

    // ---------------------------------------------------------------- Version

    public function getCurrentVersion(): string
    {
        $file = $this->storageDir . '/.version';
        return is_file($file) ? trim((string)file_get_contents($file)) : '';
    }

    public function saveCurrentVersion(string $sha): void
    {
        file_put_contents($this->storageDir . '/.version', trim($sha));
    }

    // ------------------------------------------------------------ Maintenance

    public function maintenanceOn(): void
    {
        file_put_contents($this->storageDir . '/.maintenance', date('c'));
    }

    public function maintenanceOff(): void
    {
        $file = $this->storageDir . '/.maintenance';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function isMaintenance(): bool
    {
        return is_file($this->storageDir . '/.maintenance');
    }

    // --------------------------------------------------------------- Progress

    public function getProgress(): array
    {
        $file = $this->storageDir . '/.update-progress';
        if (!is_file($file)) {
            return ['percent' => 0, 'message' => '', 'status' => 'idle'];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : ['percent' => 0, 'message' => '', 'status' => 'idle'];
    }

    private function setProgress(int $percent, string $message, string $status = 'running'): void
    {
        file_put_contents($this->storageDir . '/.update-progress', json_encode([
            'percent' => $percent,
            'message' => $message,
            'status'  => $status,
            'updated_at' => date('c'),
        ]));
    }

    // ------------------------------------------------------------ Update-Check

    /**
     * Fragt den Proxy nach verfuegbaren Updates.
     * @return array Normalisiertes Ergebnis inkl. has_update/latest_sha/changelog.
     */
    public function checkForUpdates(): array
    {
        $current = $this->getCurrentVersion();
        [$status, $body] = $this->httpGet($this->proxyUrl . '/check?current_sha=' . urlencode($current));

        $data = json_decode((string)$body, true);
        // Wichtig: Proxy kann JSON-Fehler auch mit HTTP 200 senden – nicht
        // allein auf den Statuscode verlassen.
        if (!is_array($data) || isset($data['error'])) {
            $msg = is_array($data) && isset($data['error']) ? $data['error'] : "HTTP $status";
            throw new \RuntimeException('Update-Pruefung fehlgeschlagen: ' . $msg);
        }

        return [
            'has_update'      => (bool)($data['has_update'] ?? false),
            'current_sha'     => $current,
            'latest_sha'      => $data['latest_sha'] ?? '',
            'latest_commit'   => $data['latest_commit'] ?? null,
            'versions_behind' => (int)($data['versions_behind'] ?? 0),
            'title'           => $data['title'] ?? '',
            'changelog'       => $data['changelog'] ?? '',
            'channel'         => $this->channel,
        ];
    }

    // ------------------------------------------------------------ Update-Flow

    /**
     * Fuehrt das Update durch. Reihenfolge laut Spezifikation, maintenanceOff()
     * garantiert im finally.
     *
     * @return array Ergebnis-Status
     */
    public function installUpdate(?int $userId = null): array
    {
        $this->setProgress(0, 'Update wird vorbereitet …', 'running');
        $this->maintenanceOn();

        $stagingDir = $this->storageDir . '/.update-staging';
        $zipPath = $this->storageDir . '/.update.zip';

        try {
            // 1) Neueste Version ermitteln
            $this->setProgress(5, 'Ermittle neueste Version …');
            [$status, $body] = $this->httpGet($this->proxyUrl . '/version');
            $verData = json_decode((string)$body, true);
            $latestSha = is_array($verData) ? ($verData['sha'] ?? '') : '';
            if ($latestSha === '') {
                throw new \RuntimeException('Konnte neueste Version nicht ermitteln (Proxy-Antwort ungueltig).');
            }

            // 2) ZIP herunterladen (Fallback: Einzeldatei-Download)
            $this->setProgress(20, 'Lade Update herunter …');
            $usedZip = $this->downloadZip($latestSha, $zipPath);

            // 3) Entpacken / Stagen
            $this->setProgress(45, 'Entpacke Update …');
            $this->cleanDir($stagingDir);
            if ($usedZip) {
                $this->extractZip($zipPath, $stagingDir);
            } else {
                $this->downloadFilesIndividually($latestSha, $stagingDir);
            }
            $stagingRoot = $this->resolveStagingRoot($stagingDir);

            // 4) Backup aller Dateien anlegen, die gleich ueberschrieben werden.
            //    Schlaegt das Anwenden oder eine Migration fehl, wird der alte
            //    Stand wiederhergestellt statt eine halb-aktualisierte
            //    Installation online zu nehmen.
            $this->setProgress(60, 'Sichere bestehende Dateien …');
            $backupDir = $this->storageDir . '/.backup-last';
            $this->cleanDir($backupDir);
            $this->backupExisting($stagingRoot, $backupDir);

            try {
                // 5) Staging -> Production (geschuetzte Pfade ueberspringen)
                $this->setProgress(65, 'Wende Update an …');
                $this->applyStaging($stagingRoot);

                // 6) Migrationen ausfuehren
                $this->setProgress(80, 'Fuehre Datenbank-Migrationen aus …');
                $runner = new MigrationRunner(
                    $this->db->getConnection(),
                    __DIR__ . '/migrations',
                    $this->storageDir
                );
                $runner->runPending(true);
            } catch (\Throwable $e) {
                $this->setProgress(70, 'Fehler – stelle vorherigen Stand wieder her …');
                $this->restoreBackup($backupDir);
                throw $e;
            }

            // 6) Caches leeren
            $this->setProgress(90, 'Leere Caches …');
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            // 7) Version speichern
            $this->saveCurrentVersion($latestSha);

            $this->setProgress(100, 'Update abgeschlossen.', 'done');

            if ($this->audit) {
                $this->audit->log('update_installed', ['sha' => $latestSha, 'channel' => $this->channel], $userId);
            }

            return ['success' => true, 'sha' => $latestSha];
        } catch (\Throwable $e) {
            $this->setProgress(100, 'Fehler: ' . $e->getMessage(), 'error');
            if ($this->audit) {
                $this->audit->log('update_failed', ['error' => $e->getMessage()], $userId);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            // .maintenance MUSS immer abgebaut werden.
            $this->maintenanceOff();
            @unlink($zipPath);
            $this->cleanDir($stagingDir);
            @rmdir($stagingDir);
        }
    }

    // ----------------------------------------------------------------- Helper

    private function downloadZip(string $sha, string $target): bool
    {
        $fh = @fopen($target, 'w');
        if ($fh === false) {
            return false;
        }
        $ch = curl_init($this->proxyUrl . '/zip?ref=' . urlencode($sha));
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fh);

        // Erfolg nur, wenn 200 UND es nach ZIP aussieht (Proxy kann JSON-Error 200 senden).
        if (!$ok || $code !== 200 || stripos((string)$type, 'zip') === false) {
            @unlink($target);
            return false;
        }
        if (!class_exists('ZipArchive')) {
            @unlink($target);
            return false; // Einzeldatei-Fallback nutzen
        }
        return true;
    }

    private function extractZip(string $zipPath, string $dest): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('ZIP konnte nicht geoeffnet werden.');
        }
        // Zip-Slip-Schutz: Eintraege mit Pfad-Traversal oder absoluten Pfaden
        // ablehnen, bevor irgendetwas entpackt wird.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === '' || $name[0] === '/' || strpos($name, '..') !== false || strpos($name, ':') !== false) {
                $zip->close();
                throw new \RuntimeException("ZIP enthaelt unsicheren Pfad: $name");
            }
        }
        if (!is_dir($dest)) {
            @mkdir($dest, 0775, true);
        }
        if (!$zip->extractTo($dest)) {
            $zip->close();
            throw new \RuntimeException('ZIP konnte nicht entpackt werden.');
        }
        $zip->close();
    }

    /**
     * Fallback ohne ZIP: Dateiliste vom Proxy holen und einzeln laden.
     */
    private function downloadFilesIndividually(string $sha, string $dest): void
    {
        [$status, $body] = $this->httpGet($this->proxyUrl . '/files?path=');
        $files = json_decode((string)$body, true);
        if (!is_array($files)) {
            throw new \RuntimeException('Dateiliste vom Proxy ungueltig.');
        }
        if (!is_dir($dest)) {
            @mkdir($dest, 0775, true);
        }
        foreach ($files as $entry) {
            if (($entry['type'] ?? '') !== 'file' || empty($entry['path'])) {
                continue;
            }
            $relPath = $entry['path'];
            // Pfad-Traversal-Schutz: Proxy-Antworten nicht blind vertrauen
            if ($relPath[0] === '/' || strpos($relPath, '..') !== false || strpos($relPath, ':') !== false) {
                throw new \RuntimeException("Dateiliste enthaelt unsicheren Pfad: $relPath");
            }
            [$st, $content] = $this->httpGet($this->proxyUrl . '/download/' . str_replace('%2F', '/', rawurlencode($relPath)));
            if ($st !== 200) {
                throw new \RuntimeException("Download fehlgeschlagen: $relPath (HTTP $st)");
            }
            $targetPath = $dest . '/' . $relPath;
            $dir = dirname($targetPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($targetPath, $content);
        }
    }

    /**
     * Manche Proxies/Zipballs verpacken alles in EINEN Wurzelordner
     * (z.B. "repo-<sha>/"). Diesen erkennen und als Staging-Root verwenden.
     */
    private function resolveStagingRoot(string $stagingDir): string
    {
        $entries = array_values(array_filter(scandir($stagingDir), function ($e) {
            return $e !== '.' && $e !== '..';
        }));
        if (count($entries) === 1 && is_dir($stagingDir . '/' . $entries[0])) {
            return $stagingDir . '/' . $entries[0];
        }
        return $stagingDir;
    }

    /**
     * Verschiebt/kopiert den Staging-Baum in die Produktion und ueberspringt
     * dabei alle PROTECTED_PATHS.
     */
    private function applyStaging(string $stagingRoot): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($stagingRoot))), '/');
            if ($rel === '' || $this->isProtected($rel)) {
                continue;
            }
            $target = $this->rootDir . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0775, true);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                if (!@copy($item->getPathname(), $target)) {
                    throw new \RuntimeException("Konnte Datei nicht schreiben: $rel");
                }
            }
        }
    }

    /**
     * Sichert alle Produktionsdateien, die durch das Staging ueberschrieben
     * wuerden, in ein Backup-Verzeichnis (Spiegelstruktur).
     */
    private function backupExisting(string $stagingRoot, string $backupDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($stagingRoot))), '/');
            if ($rel === '' || $this->isProtected($rel)) {
                continue;
            }
            $existing = $this->rootDir . '/' . $rel;
            if (!is_file($existing)) {
                continue;
            }
            $target = $backupDir . '/' . $rel;
            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @copy($existing, $target);
        }
    }

    /** Stellt ein zuvor angelegtes Backup wieder in die Produktion zurueck. */
    private function restoreBackup(string $backupDir): void
    {
        if (!is_dir($backupDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($backupDir))), '/');
            if ($rel === '') {
                continue;
            }
            $target = $this->rootDir . '/' . $rel;
            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @copy($item->getPathname(), $target);
        }
    }

    private function isProtected(string $rel): bool
    {
        foreach (self::PROTECTED_PATHS as $p) {
            if (substr($p, -1) === '/') {
                if (strpos($rel . '/', $p) === 0) {
                    return true;
                }
            } elseif ($rel === $p) {
                return true;
            }
        }
        return false;
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    /**
     * Einfacher HTTP-GET via curl. Gibt [httpStatus, body] zurueck.
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException("Verbindung zum Update-Proxy fehlgeschlagen: $err");
        }
        return [$status, $body];
    }
}
