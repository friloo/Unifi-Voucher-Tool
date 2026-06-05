<?php
namespace Updater;

/**
 * Minimaler AuditLogger fuer den Updater.
 *
 * Das Projekt besitzt KEINE eigene AuditLogger-Klasse, aber eine vorhandene
 * Tabelle `audit_log`. Dieser Logger schreibt dort hinein, ohne eine
 * Projekt-Klasse zu erweitern. Faellt das Schreiben fehl (z.B. Tabelle fehlt),
 * wird der Fehler still ignoriert – Logging darf ein Update nie blockieren.
 *
 * Wird dem UpdateManager optional injiziert; ist er null, wird Logging
 * komplett uebersprungen.
 */
class AuditLogger
{
    /** @var \Database */
    private $db;

    public function __construct(\Database $db)
    {
        $this->db = $db;
    }

    public function log($action, $details = null, $userId = null)
    {
        try {
            $this->db->query(
                "INSERT INTO audit_log (user_id, action, entity_type, details, ip_address)
                 VALUES (?, ?, 'updater', ?, ?)",
                [
                    $userId,
                    $action,
                    is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            // Logging darf nie ein Update verhindern.
        }
    }
}
