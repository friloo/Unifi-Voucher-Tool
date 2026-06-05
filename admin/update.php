<?php
/**
 * Admin-Entry-Point fuer den Updater (URL: /admin/update.php).
 *
 * Bewusst sehr duenn gehalten: laedt nur die Projekt-Basis + den isolierten
 * Updater-Bootstrap und delegiert alles an \Updater\UpdateController.
 *
 * Beim Rueckbau des Updaters kann diese Datei mitgeloescht werden.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../updater/bootstrap.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();

$controller = new \Updater\UpdateController($db, $auth);
$controller->handle();
