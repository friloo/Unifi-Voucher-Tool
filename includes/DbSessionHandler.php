<?php
/**
 * DbSessionHandler – speichert PHP-Sessions in der `sessions`-Tabelle.
 *
 * Opt-in über Setting `session_driver = db` (Standard 'php' = unverändert).
 * Ermöglicht "überall abmelden" und eine Übersicht aktiver Sessions.
 *
 * Alle DB-Operationen sind fehlertolerant gekapselt – schlägt etwas fehl,
 * degradiert die Session still, statt die Anwendung lahmzulegen.
 */
class DbSessionHandler implements SessionHandlerInterface
{
    private $db;
    private $ttl;

    public function __construct($db, $ttl = 3600)
    {
        $this->db = $db;
        $this->ttl = max(300, (int)$ttl);
    }

    #[\ReturnTypeWillChange]
    public function open($path, $name) { return true; }

    #[\ReturnTypeWillChange]
    public function close() { return true; }

    #[\ReturnTypeWillChange]
    public function read($id)
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT data FROM sessions WHERE id = ? AND expires_at > NOW()", [$id]
            );
            return $row && $row['data'] !== null ? (string)$row['data'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    #[\ReturnTypeWillChange]
    public function write($id, $data)
    {
        try {
            $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $expires = date('Y-m-d H:i:s', time() + $this->ttl);
            $this->db->query(
                "INSERT INTO sessions (id, user_id, data, expires_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), data = VALUES(data), expires_at = VALUES(expires_at)",
                [$id, $uid, $data, $expires]
            );
        } catch (\Throwable $e) {
            // still ignorieren
        }
        return true;
    }

    #[\ReturnTypeWillChange]
    public function destroy($id)
    {
        try {
            $this->db->query("DELETE FROM sessions WHERE id = ?", [$id]);
        } catch (\Throwable $e) {}
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc($max_lifetime)
    {
        try {
            $this->db->query("DELETE FROM sessions WHERE expires_at < NOW()");
        } catch (\Throwable $e) {}
        return true;
    }
}
