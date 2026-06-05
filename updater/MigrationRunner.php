<?php
namespace Updater;

/**
 * MigrationRunner – fuehrt ausschliesslich Updater-eigene SQL-Migrationen aus
 * (Ordner updater/migrations/). Produktive Schema-Migrationen des Projekts
 * (database.sql) werden NICHT angefasst.
 *
 * DB-Treiber dieses Projekts: MySQL/MariaDB. Die Tracking-Tabelle traegt das
 * Praefix `_updater_`, damit sie sich nicht mit einer evtl. vorhandenen
 * `_migrations`-Tabelle beisst.
 */
class MigrationRunner
{
    /** @var \PDO */
    private $pdo;
    private $driver;
    private $migrationsDir;
    private $lockFile;

    public function __construct(\PDO $pdo, $migrationsDir, $storageDir)
    {
        $this->pdo = $pdo;
        $this->driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->migrationsDir = rtrim($migrationsDir, '/');
        $this->lockFile = rtrim($storageDir, '/') . '/.migrations-lock';
    }

    /** Stellt die Tracking-Tabelle sicher (treiber-spezifisch). */
    public function ensureTable()
    {
        if ($this->driver === 'sqlite') {
            $sql = 'CREATE TABLE IF NOT EXISTS "_updater_migrations" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "filename" TEXT NOT NULL UNIQUE,
                "applied_at" DATETIME DEFAULT CURRENT_TIMESTAMP
            )';
        } else {
            $sql = 'CREATE TABLE IF NOT EXISTS `_updater_migrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `filename` VARCHAR(255) NOT NULL UNIQUE,
                `applied_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }
        $this->pdo->exec($sql);
    }

    /**
     * Fuehrt alle noch nicht angewandten Migrationen aus.
     * 60-Sekunden-Cache via Lockfile-Timestamp verhindert, dass bei haeufigen
     * Aufrufen unnoetig gescannt wird.
     *
     * @param bool $force Cache ignorieren (z.B. direkt nach einem Update)
     * @return array Liste der angewandten Dateinamen
     */
    public function runPending($force = false)
    {
        if (!$force && $this->isCacheFresh()) {
            return [];
        }

        $this->ensureTable();
        $applied = $this->appliedFilenames();
        $files = $this->migrationFiles();
        $done = [];

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            $statements = $this->splitStatements($sql);

            $this->pdo->beginTransaction();
            try {
                foreach ($statements as $stmt) {
                    if (trim($stmt) === '') {
                        continue;
                    }
                    try {
                        $this->pdo->exec($stmt);
                    } catch (\PDOException $e) {
                        if (!$this->isIgnorableSqlError($e->getMessage(), $this->driver)) {
                            throw $e;
                        }
                    }
                }
                $ins = $this->pdo->prepare(
                    $this->driver === 'sqlite'
                        ? 'INSERT OR IGNORE INTO "_updater_migrations" (filename) VALUES (?)'
                        : 'INSERT IGNORE INTO `_updater_migrations` (filename) VALUES (?)'
                );
                $ins->execute([$name]);
                $this->pdo->commit();
                $done[] = $name;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new \RuntimeException("Migration $name fehlgeschlagen: " . $e->getMessage(), 0, $e);
            }
        }

        $this->touchCache();
        return $done;
    }

    /** Liefert Status aller Migrationen (angewandt / offen). */
    public function status()
    {
        $this->ensureTable();
        $applied = $this->appliedFilenames();
        $result = [];
        foreach ($this->migrationFiles() as $file) {
            $name = basename($file);
            $result[] = ['filename' => $name, 'applied' => in_array($name, $applied, true)];
        }
        return $result;
    }

    private function appliedFilenames()
    {
        try {
            $rows = $this->pdo->query('SELECT filename FROM ' .
                ($this->driver === 'sqlite' ? '"_updater_migrations"' : '`_updater_migrations`'))->fetchAll(\PDO::FETCH_COLUMN);
            return $rows ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function migrationFiles()
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }
        $files = glob($this->migrationsDir . '/*.sql');
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * String- und kommentar-bewusster SQL-Splitter. Trennt an ';' ausserhalb
     * von Strings/Kommentaren. Verhindert das naive Zerschneiden von Statements,
     * die ';' innerhalb von String-Literalen enthalten.
     */
    private function splitStatements($sql)
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $inSingle = false;   // '...'
        $inDouble = false;   // "..."
        $inBacktick = false; // `...`
        $inLineComment = false;   // -- ... oder # ...
        $inBlockComment = false;  // /* ... */

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                    $buffer .= $ch;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($ch === '-' && $next === '-') { $inLineComment = true; $i++; continue; }
                if ($ch === '#') { $inLineComment = true; continue; }
                if ($ch === '/' && $next === '*') { $inBlockComment = true; $i++; continue; }
            }

            // String-/Identifier-Grenzen umschalten (mit Backslash-Escape)
            if ($ch === "'" && !$inDouble && !$inBacktick) {
                if ($inSingle && $this->isEscaped($sql, $i)) { $buffer .= $ch; continue; }
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
                if ($inDouble && $this->isEscaped($sql, $i)) { $buffer .= $ch; continue; }
                $inDouble = !$inDouble;
            } elseif ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }
        return $statements;
    }

    private function isEscaped($sql, $pos)
    {
        $backslashes = 0;
        $p = $pos - 1;
        while ($p >= 0 && $sql[$p] === '\\') { $backslashes++; $p--; }
        return ($backslashes % 2) === 1;
    }

    /**
     * Treiber-spezifische Liste ignorierbarer SQL-Fehler (idempotente
     * Migrationen: Spalte/Index/Tabelle existiert bereits o.ae.).
     */
    public function isIgnorableSqlError($msg, $driver)
    {
        $msg = strtolower($msg);
        $common = [
            'already exists',
            'duplicate column',
            'duplicate key name',
        ];
        foreach ($common as $needle) {
            if (strpos($msg, $needle) !== false) {
                return true;
            }
        }
        if ($driver === 'sqlite') {
            return strpos($msg, 'duplicate column name') !== false
                || strpos($msg, 'already exists') !== false;
        }
        // MySQL/MariaDB
        return strpos($msg, "can't drop") !== false && strpos($msg, 'check that column/key exists') !== false;
    }

    private function isCacheFresh()
    {
        return is_file($this->lockFile) && (time() - filemtime($this->lockFile)) < 60;
    }

    private function touchCache()
    {
        @touch($this->lockFile);
    }
}
