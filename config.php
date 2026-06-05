<?php
// UniFi Voucher Management System - Configuration

define('DB_HOST', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

// Anwendungs-Schluessel fuer Verschluesselung-at-rest (UniFi-Passwoerter).
// Wird vom Installer automatisch mit einem zufaelligen Wert befuellt.
// Leer = keine Verschluesselung (Klartext, Legacy-Verhalten).
define('APP_KEY', '');

// Sitzungs-Einstellungen
define('SESSION_LIFETIME', 3600); // 1 Stunde

// Zeitzone
date_default_timezone_set('Europe/Berlin');
